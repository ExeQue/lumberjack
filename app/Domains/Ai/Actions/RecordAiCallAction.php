<?php

declare(strict_types=1);

namespace App\Domains\Ai\Actions;

use App\Domains\Ai\Enums\AiCallSource;
use App\Domains\Ai\Enums\AiCallStatus;
use App\Domains\Ai\Enums\AiOperation;
use App\Domains\Ai\Support\AiKeyFingerprint;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A call an app made to a model: kept as it came, and added to the hourly and daily totals,
 * which outlive it. The key it was made with arrives as its fingerprint: the app sent a
 * SHA-256 of driver and key, which the ingest endpoint turned into an HMAC with the
 * project's secret before queueing the batch (AiKeyFingerprint::seal), so the hash is never
 * queued. Calls wait for one bulk write at the end of the batch (see flush).
 */
class RecordAiCallAction
{
    public const int INSERT_CHUNK = 500;

    /** Anything longer is a broken measurement. */
    public const int MAX_DURATION_MS = 86_400_000;

    public const int MAX_STEP = 1_000_000;

    /**
     * Tokens one call can report per kind, at most; anything more is a broken measurement.
     * Far below what a bigint total holds, so no sum of calls overflows it.
     */
    public const int MAX_TOKENS = 10_000_000_000;

    /** The counters both totals keep, as the call's column for each (null: the call itself). */
    private const array COUNTERS = [
        'calls' => null,
        'errors' => null,
        'input_tokens' => 'input_tokens',
        'output_tokens' => 'output_tokens',
        'cache_read_tokens' => 'cache_read_tokens',
        'cache_write_tokens' => 'cache_write_tokens',
        'reasoning_tokens' => 'reasoning_tokens',
        'duration_ms_sum' => 'duration_ms',
    ];

    /** The columns an hourly total is unique by. */
    private const array HOURLY_KEY = ['project_id', 'environment', 'provider', 'model', 'fingerprint', 'owner_type', 'owner_id', 'hour'];

    /** The columns a daily total is unique by. */
    private const array DAILY_KEY = ['project_id', 'environment', 'provider', 'model', 'fingerprint', 'owner_type', 'owner_id', 'actor_type', 'actor_id', 'day'];

    /** @var list<array<string, mixed>> calls waiting for one bulk insert */
    private array $pending = [];

    /** @var array<string, array<string, mixed>> keys seen, waiting for one upsert, by project and fingerprint */
    private array $keys = [];

    /** @var array<string, array<string, mixed>> hourly totals waiting for one upsert, by key */
    private array $hourly = [];

    /** @var array<string, array<string, mixed>> daily totals waiting for one upsert, by key */
    private array $daily = [];

    /**
     * @param  array<string, mixed>  $event
     * @return bool whether the call was kept
     */
    public function execute(Project $project, array $event, Envelope $envelope): bool
    {
        $provider = self::lower(Envelope::short($event['provider'] ?? null));
        $model = Envelope::short($event['model'] ?? null, 128);

        if ($provider === null || $model === null) {
            return false;
        }

        $at = Envelope::time($event['timestamp'] ?? null);
        $tokens = is_array($event['tokens'] ?? null) ? $event['tokens'] : [];
        $key = is_array($event['key'] ?? null) ? $event['key'] : [];
        [$ownerType, $ownerId] = self::reference($key['owner'] ?? null);
        [$actorType, $actorId] = self::reference($event['actor'] ?? null);
        $error = Envelope::short($event['error'] ?? null, 255);
        $status = AiCallStatus::tryFrom((string) ($event['status'] ?? '')) ?? ($error !== null ? AiCallStatus::Error : AiCallStatus::Ok);
        $fingerprint = AiKeyFingerprint::sealed($key);

        $row = [
            'project_id' => $project->id,
            'fingerprint' => $fingerprint,
            'provider' => $provider,
            'driver' => self::lower(Envelope::short($event['driver'] ?? null)),
            'model' => $model,
            // A newer SDK's operation still counts; it is kept as text until Lumberjack knows it.
            'operation' => (AiOperation::tryFrom((string) ($event['operation'] ?? '')) ?? AiOperation::Text)->value,
            'input_tokens' => self::count($tokens['input'] ?? null, self::MAX_TOKENS),
            'output_tokens' => self::count($tokens['output'] ?? null, self::MAX_TOKENS),
            'cache_read_tokens' => self::count($tokens['cache_read'] ?? null, self::MAX_TOKENS),
            'cache_write_tokens' => self::count($tokens['cache_write'] ?? null, self::MAX_TOKENS),
            'reasoning_tokens' => self::count($tokens['reasoning'] ?? null, self::MAX_TOKENS),
            'duration_ms' => isset($event['duration_ms']) && is_numeric($event['duration_ms']) && $event['duration_ms'] >= 0
                ? min((float) $event['duration_ms'], self::MAX_DURATION_MS)
                : null,
            'status' => $status->value,
            'error' => $error,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'invocation_id' => Envelope::short($event['invocation_id'] ?? null),
            'step' => self::count($event['step'] ?? null, self::MAX_STEP),
            'trace_id' => Envelope::short($event['trace_id'] ?? null),
            'environment' => $envelope->environment,
            'release' => $envelope->release,
            'host' => $envelope->host,
            'source' => (AiCallSource::tryFrom((string) ($event['source'] ?? '')) ?? AiCallSource::Manual)->value,
            'occurred_at' => $at->format('Y-m-d H:i:s.v'),
        ];

        $this->pending[] = $row;

        if ($fingerprint !== null) {
            $this->seeKey($row, Envelope::short($key['label'] ?? null, 255), $at->format('Y-m-d H:i:s'));
        }

        $common = [
            'project_id' => $project->id,
            'environment' => $envelope->environment ?? '',
            'provider' => $provider,
            'model' => $model,
            'fingerprint' => $fingerprint ?? '',
            'owner_type' => $ownerType ?? '',
            'owner_id' => $ownerId ?? '',
        ];

        $this->add($this->hourly, [...$common, 'hour' => $at->startOfHour()->format('Y-m-d H:i:s')], $row);
        $this->add($this->daily, [
            ...$common,
            'actor_type' => $actorType ?? '',
            'actor_id' => $actorId ?? '',
            'day' => $at->format('Y-m-d'),
        ], $row);

        return true;
    }

    /**
     * Stores the calls, keys and totals still waiting. Call it once the batch is through.
     *
     * @param  string|null  $batch  the queued batch, the same on every attempt: once its calls
     *                              are stored, another attempt stores nothing
     */
    public function flush(?string $batch = null): void
    {
        [$pending, $keys, $hourly, $daily] = [$this->pending, $this->keys, $this->hourly, $this->daily];
        [$this->pending, $this->keys, $this->hourly, $this->daily] = [[], [], [], []];

        if ($pending === []) {
            return;
        }

        if ($batch !== null) {
            $pending = array_map(fn (array $call): array => [...$call, 'batch' => $batch], $pending);
        }

        // All or nothing: a failure leaves no calls without their totals.
        DB::transaction(function () use ($pending, $keys, $hourly, $daily, $batch): void {
            if ($batch !== null && $this->stored($batch)) {
                return;
            }

            foreach (array_chunk($pending, self::INSERT_CHUNK) as $chunk) {
                DB::table('ai_calls')->insert($chunk);
            }

            // One order for every writer, so two batches upserting the same rows do not deadlock.
            ksort($keys);

            foreach (array_chunk(array_values($keys), self::INSERT_CHUNK) as $chunk) {
                // A key keeps its label, owner and provider until a call says otherwise.
                DB::table('ai_keys')->upsert($chunk, ['project_id', 'fingerprint'], [
                    'first_seen_at' => DB::raw('LEAST(ai_keys.first_seen_at, excluded.first_seen_at)'),
                    'last_seen_at' => DB::raw('GREATEST(ai_keys.last_seen_at, excluded.last_seen_at)'),
                    'provider' => DB::raw('COALESCE(excluded.provider, ai_keys.provider)'),
                    'driver' => DB::raw('COALESCE(excluded.driver, ai_keys.driver)'),
                    'label' => DB::raw('COALESCE(excluded.label, ai_keys.label)'),
                    'owner_type' => DB::raw('COALESCE(excluded.owner_type, ai_keys.owner_type)'),
                    'owner_id' => DB::raw('COALESCE(excluded.owner_id, ai_keys.owner_id)'),
                ]);
            }

            $this->upsertTotals('ai_usage_hourly', self::HOURLY_KEY, $hourly);
            $this->upsertTotals('ai_usage_daily', self::DAILY_KEY, $daily);
        });
    }

    /**
     * Whether an earlier attempt at the batch stored its calls. Two attempts at once wait for
     * each other on the batch's lock, so only one stores them.
     */
    private function stored(string $batch): bool
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$batch]);

        return DB::table('ai_calls')->where('batch', $batch)->exists();
    }

    /**
     * Remembers a key for the upsert: first and last seen over the batch, and the last label
     * and owner a call of it gave.
     *
     * @param  array<string, mixed>  $call
     */
    private function seeKey(array $call, ?string $label, string $at): void
    {
        $id = $call['project_id']."\0".$call['fingerprint'];
        $seen = $this->keys[$id] ?? null;
        $owned = $call['owner_type'] !== null;

        $this->keys[$id] = [
            'uuid' => $seen['uuid'] ?? (string) Str::uuid7(),
            'project_id' => $call['project_id'],
            'fingerprint' => $call['fingerprint'],
            'provider' => $call['provider'],
            'driver' => $call['driver'] ?? $seen['driver'] ?? null,
            'label' => $label ?? $seen['label'] ?? null,
            'owner_type' => $owned ? $call['owner_type'] : $seen['owner_type'] ?? null,
            'owner_id' => $owned ? $call['owner_id'] : $seen['owner_id'] ?? null,
            'first_seen_at' => $seen !== null ? min($seen['first_seen_at'], $at) : $at,
            'last_seen_at' => $seen !== null ? max($seen['last_seen_at'], $at) : $at,
        ];
    }

    /**
     * Adds a call to the total its key columns name.
     *
     * @param  array<string, array<string, mixed>>  $totals
     * @param  array<string, string|int>  $columns
     * @param  array<string, mixed>  $call
     */
    private function add(array &$totals, array $columns, array $call): void
    {
        $id = implode("\0", $columns);
        $totals[$id] ??= [...$columns, ...array_fill_keys(array_keys(self::COUNTERS), 0)];

        foreach (self::COUNTERS as $counter => $column) {
            $totals[$id][$counter] += match ($counter) {
                'calls' => 1,
                'errors' => $call['status'] === AiCallStatus::Error->value ? 1 : 0,
                default => $call[$column] ?? 0,
            };
        }
    }

    /**
     * @param  'ai_usage_hourly'|'ai_usage_daily'  $table
     * @param  non-empty-list<non-empty-string>  $keyColumns
     * @param  array<string, array<string, mixed>>  $totals
     */
    private function upsertTotals(string $table, array $keyColumns, array $totals): void
    {
        if ($totals === []) {
            return;
        }

        ksort($totals);
        $update = [];

        foreach (self::COUNTERS as $counter => $column) {
            $update[$counter] = DB::raw("{$table}.{$counter} + excluded.{$counter}");
        }

        foreach (array_chunk(array_values($totals), self::INSERT_CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, $keyColumns, $update);
        }
    }

    private static function lower(?string $value): ?string
    {
        return $value !== null ? mb_strtolower($value) : null;
    }

    /**
     * A whole number of at least zero, or null for anything else (unknown, negative, a fraction).
     */
    private static function count(mixed $value, int $max = PHP_INT_MAX): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $max]]);

        return $count === false ? null : $count;
    }

    /**
     * An actor or owner as its type and key, or nothing when either is missing.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function reference(mixed $reference): array
    {
        if (! is_array($reference)) {
            return [null, null];
        }

        // Trimmed, as the pages' links are: a type or id of only spaces is none.
        $type = Str::trim((string) Envelope::short($reference['type'] ?? null, 128));
        $id = Str::trim((string) Envelope::short($reference['id'] ?? null, 64));

        return $type !== '' && $id !== '' ? [$type, $id] : [null, null];
    }
}
