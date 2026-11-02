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

/**
 * A log line lands in its channel; an unknown channel name lands in default with
 * the name it was sent to. Lines at error and above also become issues.
 */
class RecordLogLineAction
{
    /** @var array<int, Collection<string, LogChannel>> */
    private array $channels = [];

    public function __construct(
        private readonly RecordOccurrenceAction $recordOccurrenceAction,
        private readonly SignalFactory $signalFactory,
        private readonly Scrubber $scrubber,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function execute(Project $project, array $event, Envelope $envelope): LogEntry
    {
        $sent = Envelope::short($event['channel'] ?? null) ?? LogChannel::DEFAULT;
        $channel = $this->channel($project, $sent);
        $level = is_numeric($event['level'] ?? null) ? (LogLevel::tryFrom((int) $event['level']) ?? LogLevel::Info) : LogLevel::fromName((string) ($event['level'] ?? 'info'));
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];
        $exception = is_array($context['exception'] ?? null) ? $context['exception'] : null;
        unset($context['exception']);

        $entry = LogEntry::create([
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
        ]);

        if ($level->createsIssue()) {
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
        }

        return $entry;
    }

    private function channel(Project $project, string $name): LogChannel
    {
        $this->channels[$project->id] ??= $project->logChannels()->get()->keyBy('name');

        return $this->channels[$project->id][$name]
            ?? $this->channels[$project->id][LogChannel::DEFAULT]
            ?? $project->logChannels()->firstOrCreate(['name' => LogChannel::DEFAULT]);
    }
}
