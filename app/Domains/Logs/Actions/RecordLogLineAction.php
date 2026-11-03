<?php

declare(strict_types=1);

namespace App\Domains\Logs\Actions;

use App\Domains\Ingest\Data\Envelope;
use App\Domains\Ingest\Support\SignalFactory;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Logs\Enums\LogLevel;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Logs\Models\LogEntry;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\Scrubber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A log line lands in its channel; an unknown channel name lands in default with
 * the name it was sent to. Lines at error and above also become issues.
 */
class RecordLogLineAction
{
    public const int INSERT_CHUNK = 500;

    /**
     * Per instance, and the action is resolved per ingest job: a singleton would keep
     * stale channels and grow per project.
     *
     * @var array<int, Collection<string, LogChannel>>
     */
    private array $channels = [];

    /** @var list<array<string, mixed>> lines waiting for one bulk insert */
    private array $pending = [];

    public function __construct(
        private readonly RecordOccurrenceAction $recordOccurrenceAction,
        private readonly SignalFactory $signalFactory,
        private readonly Scrubber $scrubber,
    ) {
    }

    /**
     * Lines that do not become issues wait for one bulk insert (see flush), so a batch
     * of lines is a few statements, not one per line; those return null. A line that
     * becomes an issue is stored at once, after the waiting ones so ids keep the order.
     *
     * @param  array<string, mixed>  $event
     */
    public function execute(Project $project, array $event, Envelope $envelope): ?LogEntry
    {
        $sent = Envelope::short($event['channel'] ?? null) ?? LogChannel::DEFAULT;
        $channel = $this->channel($project, $sent);
        $level = is_numeric($event['level'] ?? null) ? (LogLevel::tryFrom((int) $event['level']) ?? LogLevel::Info) : LogLevel::fromName((string) ($event['level'] ?? 'info'));
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];
        $exception = is_array($context['exception'] ?? null) ? $context['exception'] : null;
        unset($context['exception']);

        $attributes = [
            'project_id' => $project->id,
            'log_channel_id' => $channel->id,
            'sent_channel' => $channel->name === $sent ? null : $sent,
            'level' => $level,
            'message' => mb_substr((string) ($event['message'] ?? ''), 0, 20_000),
            'context' => $context === [] ? null : $this->scrubber->scrub($context, $project->scrub_keys ?? []),
            'trace_id' => Envelope::short($event['trace_id'] ?? null),
            'environment' => $envelope->environment,
            'release' => $envelope->release,
            'host' => $envelope->host,
            'occurred_at' => Envelope::time($event['timestamp'] ?? null),
        ];

        if (! $level->createsIssue()) {
            $this->pending[] = [
                ...$attributes,
                'level' => $level->value,
                'context' => $attributes['context'] !== null ? json_encode($attributes['context']) : null,
                'occurred_at' => $attributes['occurred_at']->format('Y-m-d H:i:s.v'),
            ];

            if (count($this->pending) >= self::INSERT_CHUNK) {
                $this->flush();
            }

            return null;
        }

        $this->flush();
        $entry = LogEntry::create($attributes);

        $signal = $exception !== null
            ? $this->signalFactory->fromException([...$exception, 'trace_id' => $event['trace_id'] ?? null, 'timestamp' => $event['timestamp'] ?? null], $envelope, IssueSource::Server, $project->scrub_keys ?? [])
            : new IssueSignal(
                source: IssueSource::Log,
                title: $channel->name.': '.mb_substr($entry->message, 0, 200),
                message: $entry->message,
                file: null,
                line: null,
                stacktrace: [],
                context: ['log' => array_filter(['channel' => $channel->name, 'sent_channel' => $entry->sent_channel, 'level' => $level->key(), 'entry_id' => $entry->id], fn ($value): bool => $value !== null), 'context' => $entry->context],
                occurredAt: $entry->occurred_at->toImmutable(),
                traceId: $entry->trace_id,
                environment: $envelope->environment,
                release: $envelope->release,
                host: $envelope->host,
                logChannel: $channel->name,
            );

        $this->recordOccurrenceAction->execute($project, $signal);

        return $entry;
    }

    /**
     * Stores the lines still waiting. Call it once the batch is through.
     */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $rows = $this->pending;
        $this->pending = [];
        DB::table('log_entries')->insert($rows);
    }

    private function channel(Project $project, string $name): LogChannel
    {
        $this->channels[$project->id] ??= $project->logChannels()->get()->keyBy('name');

        return $this->channels[$project->id][$name]
            ?? $this->channels[$project->id][LogChannel::DEFAULT]
            ?? $project->logChannels()->firstOrCreate(['name' => LogChannel::DEFAULT]);
    }
}
