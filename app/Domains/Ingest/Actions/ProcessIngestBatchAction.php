<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Actions;

use App\Domains\Commands\Actions\RecordCommandEventAction;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Ingest\Support\SignalFactory;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Jobs\Actions\RecordJobEventAction;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Logs\Actions\RecordLogLineAction;
use App\Domains\Performance\Support\SqlNormalizer;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Actions\RecordScheduleEventAction;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stores one batch from an agent or a browser. A malformed event is skipped
 * and reported; it never costs the rest of the batch.
 */
class ProcessIngestBatchAction
{
    public const int INSERT_CHUNK = 500;

    public function __construct(
        private readonly RecordOccurrenceAction $recordOccurrenceAction,
        private readonly RecordJobEventAction $recordJobEventAction,
        private readonly RecordLogLineAction $recordLogLineAction,
        private readonly RecordScheduleEventAction $recordScheduleEventAction,
        private readonly RecordCommandEventAction $recordCommandEventAction,
        private readonly SignalFactory $signalFactory,
        private readonly SqlNormalizer $sqlNormalizer,
        private readonly BroadcastProjectChangeAction $broadcast,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(Project $project, array $payload, bool $fromBrowser = false): void
    {
        $envelope = Envelope::fromPayload($payload);
        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];

        $requests = [];
        $queries = [];
        $touched = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? null;

            // A browser may only report errors; everything else needs the server token.
            if ($fromBrowser && $type !== 'exception') {
                continue;
            }

            try {
                match ($type) {
                    'request' => $requests[] = $this->requestRow($project, $event, $envelope),
                    'query' => $queries[] = $this->queryRow($project, $event, $envelope),
                    'exception' => $this->recordOccurrenceAction->execute($project, $this->signalFactory->fromException($event, $envelope, $fromBrowser ? IssueSource::Browser : IssueSource::Server, $project->scrub_keys ?? [])),
                    'job' => $this->recordJobEventAction->execute($project, $event, $envelope),
                    'log' => $this->recordLogLineAction->execute($project, $event, $envelope),
                    'schedule' => $this->recordScheduleEventAction->execute($project, $event, $envelope),
                    'command' => $this->recordCommandEventAction->execute($project, $event, $envelope),
                    default => null,
                };
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $touched[(string) $type] = true;
        }

        foreach (array_chunk($requests, self::INSERT_CHUNK) as $chunk) {
            DB::table('request_runs')->insert($chunk);
        }

        foreach (array_chunk($queries, self::INSERT_CHUNK) as $chunk) {
            DB::table('query_runs')->insert($chunk);
        }

        $this->broadcastChanges($project, $touched);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function requestRow(Project $project, array $event, Envelope $envelope): array
    {
        return [
            'project_id' => $project->id,
            'trace_id' => Envelope::short($event['trace_id'] ?? null),
            'environment' => $envelope->environment,
            'release' => $envelope->release,
            'host' => $envelope->host,
            'method' => mb_substr(strtoupper((string) ($event['method'] ?? 'GET')), 0, 10),
            'route' => Envelope::short($event['route'] ?? null, 255),
            'url' => mb_substr((string) ($event['url'] ?? ''), 0, 2000),
            'status_code' => max(0, min(999, (int) ($event['status'] ?? 0))),
            'duration_us' => Envelope::micros($event['duration_ms'] ?? 0),
            'memory_peak' => isset($event['memory_peak']) && is_numeric($event['memory_peak']) ? (int) $event['memory_peak'] : null,
            'occurred_at' => Envelope::time($event['timestamp'] ?? null)->format('Y-m-d H:i:s.v'),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function queryRow(Project $project, array $event, Envelope $envelope): array
    {
        $sql = $this->sqlNormalizer->normalize((string) ($event['sql'] ?? ''));

        return [
            'project_id' => $project->id,
            'trace_id' => Envelope::short($event['trace_id'] ?? null),
            'environment' => $envelope->environment,
            'connection' => Envelope::short($event['connection'] ?? null) ?? 'default',
            'sql' => $sql,
            'sql_hash' => $this->sqlNormalizer->hash($sql),
            'location' => Envelope::short($event['location'] ?? null, 255),
            'duration_us' => Envelope::micros($event['duration_ms'] ?? 0),
            'occurred_at' => Envelope::time($event['timestamp'] ?? null)->format('Y-m-d H:i:s.v'),
        ];
    }

    /**
     * @param  array<string, true>  $touched
     */
    private function broadcastChanges(Project $project, array $touched): void
    {
        $kinds = [];

        if (isset($touched['exception']) || isset($touched['log']) || isset($touched['job']) || isset($touched['schedule']) || isset($touched['command'])) {
            $kinds[] = ChangeKind::Issues;
        }

        if (isset($touched['job'])) {
            $kinds[] = ChangeKind::Jobs;
        }

        if (isset($touched['schedule'])) {
            $kinds[] = ChangeKind::Schedule;
        }

        if (isset($touched['command'])) {
            $kinds[] = ChangeKind::Commands;
        }

        if (isset($touched['log'])) {
            $kinds[] = ChangeKind::Logs;
        }

        if (isset($touched['request']) || isset($touched['query'])) {
            $kinds[] = ChangeKind::Performance;
        }

        foreach ($kinds as $kind) {
            $this->broadcast->execute($project->id, $kind);
        }
    }
}
