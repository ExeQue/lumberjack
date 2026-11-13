<?php

declare(strict_types=1);

namespace App\Domains\Activity\Actions;

use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Models\ActivityCount;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\Scrubber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An event an app recorded: kept as it came, and counted per hour so the counts outlive
 * it. Events wait for one bulk insert (see flush), so a batch of them is a few statements.
 * An actor and a subject are a type and a key only; the app sends nothing more about them.
 */
class RecordActivityAction
{
    public const int INSERT_CHUNK = 500;

    /** A payload larger than this, as JSON, is replaced by a marker. */
    public const int MAX_PAYLOAD_BYTES = 16_384;

    /** What a payload too large to keep becomes. */
    public const array TRUNCATED = ['_truncated' => true];

    private const string NAME_PATTERN = '/^[A-Za-z0-9._:\-]+$/';

    /** @var list<array<string, mixed>> events waiting for one bulk insert */
    private array $pending = [];

    /** @var array<string, array<string, mixed>> hourly counts waiting for one upsert, by key */
    private array $counts = [];

    public function __construct(
        private readonly Scrubber $scrubber,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     * @return bool whether the event was kept
     */
    public function execute(Project $project, array $event, Envelope $envelope): bool
    {
        $name = Envelope::short($event['name'] ?? null, 128);

        if ($name === null || preg_match(self::NAME_PATTERN, $name) !== 1) {
            return false;
        }

        $group = self::group($event['group'] ?? null);
        $at = Envelope::time($event['timestamp'] ?? null);
        [$actorType, $actorId] = $this->reference($event['actor'] ?? null);
        [$subjectType, $subjectId] = $this->reference($event['subject'] ?? null);
        $payload = $this->payload($project, $event['payload'] ?? null);

        $this->pending[] = [
            'project_id' => $project->id,
            'group' => $group,
            'name' => $name,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload !== null ? json_encode($payload) : null,
            'trace_id' => Envelope::short($event['trace_id'] ?? null),
            'environment' => $envelope->environment,
            'release' => $envelope->release,
            'host' => $envelope->host,
            'occurred_at' => $at->format('Y-m-d H:i:s.v'),
        ];

        $hour = $at->startOfHour()->format('Y-m-d H:i:s');
        $key = implode("\0", [$project->id, $envelope->environment ?? '', $group, $name, $hour]);
        $this->counts[$key] ??= ['project_id' => $project->id, 'environment' => $envelope->environment ?? '', 'group' => $group, 'name' => $name, 'hour' => $hour, 'count' => 0];
        $this->counts[$key]['count']++;

        if (count($this->pending) >= self::INSERT_CHUNK) {
            $this->flush();
        }

        return true;
    }

    /**
     * Stores the events and counts still waiting. Call it once the batch is through.
     */
    public function flush(): void
    {
        if ($this->pending !== []) {
            DB::table('activities')->insert($this->pending);
            $this->pending = [];
        }

        if ($this->counts === []) {
            return;
        }

        // One order for every writer, so two batches upserting the same rows do not deadlock.
        ksort($this->counts);

        foreach (array_chunk(array_values($this->counts), self::INSERT_CHUNK) as $chunk) {
            ActivityCount::query()->upsert($chunk, ['project_id', 'environment', 'group', 'name', 'hour'], [
                'count' => DB::raw('activity_counts.count + excluded.count'),
            ]);
        }

        $this->counts = [];
    }

    /**
     * A group as a slug, like an alert topic; the default group when none is given.
     */
    public static function group(mixed $group): string
    {
        return Str::limit(Str::slug((string) Envelope::short($group, 64)), 64, '') ?: Activity::DEFAULT_GROUP;
    }

    /**
     * An actor or subject as its type and key, or nothing when either is missing.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function reference(mixed $reference): array
    {
        if (! is_array($reference)) {
            return [null, null];
        }

        $type = Envelope::short($reference['type'] ?? null, 128);
        $id = Envelope::short($reference['id'] ?? null, 64);

        return $type !== null && $id !== null ? [$type, $id] : [null, null];
    }

    /**
     * The payload scrubbed of secrets, or the marker when it is too large to keep.
     *
     * @return array<string, mixed>|null
     */
    private function payload(Project $project, mixed $payload): ?array
    {
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $payload = $this->scrubber->scrub($payload, $project->scrub_keys ?? []);

        return strlen((string) json_encode($payload)) <= self::MAX_PAYLOAD_BYTES ? $payload : self::TRUNCATED;
    }
}
