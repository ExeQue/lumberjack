<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Actions;

use App\Domains\Commands\Actions\RecordCommandEventAction;
use App\Domains\Health\Actions\RecordReportedHealthAction;
use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Ingest\Support\SignalFactory;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Jobs\Actions\RecordJobEventAction;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Logs\Actions\RecordLogLineAction;
use App\Domains\Performance\Actions\RecordRequestCountsAction;
use App\Domains\Performance\Enums\SpanKind;
use App\Domains\Performance\Support\SqlNormalizer;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Actions\RecordScheduleEventAction;
use App\Domains\Shared\Support\Scrubber;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Stores one batch from an agent or a browser. A malformed event is skipped
 * and reported; it never costs the rest of the batch.
 */
class ProcessIngestBatchAction
{
    public const int INSERT_CHUNK = 500;

    /** Anything slower is a broken measurement (a tab left in the background), not a slow page. */
    public const int MAX_VITAL_MS = 60_000;

    public const int MAX_CLS = 10;

    public function __construct(
        private readonly RecordOccurrenceAction $recordOccurrenceAction,
        private readonly RecordJobEventAction $recordJobEventAction,
        private readonly RecordLogLineAction $recordLogLineAction,
        private readonly RecordScheduleEventAction $recordScheduleEventAction,
        private readonly RecordCommandEventAction $recordCommandEventAction,
        private readonly RecordReportedHealthAction $recordReportedHealthAction,
        private readonly RecordRequestCountsAction $recordRequestCountsAction,
        private readonly SignalFactory $signalFactory,
        private readonly SqlNormalizer $sqlNormalizer,
        private readonly BroadcastProjectChangeAction $broadcast,
        private readonly Scrubber $scrubber,
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
        $spans = [];
        $vitals = [];
        $counts = [];
        $touched = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? null;

            // A browser may only report errors and web vitals; everything else needs the server token.
            if ($fromBrowser && ! in_array($type, ['exception', 'vital'], true)) {
                continue;
            }

            try {
                match ($type) {
                    'request' => $requests[] = $this->requestRow($project, $event, $envelope),
                    'query' => $queries[] = $this->queryRow($project, $event, $envelope),
                    'span' => $spans[] = $this->spanRow($project, $event, $envelope),
                    'vital' => $vitals[] = $this->vitalRow($project, $event, $envelope),
                    'request_counts' => $counts[] = $event,
                    'exception' => $this->recordOccurrenceAction->execute($project, $this->signalFactory->fromException($event, $envelope, $fromBrowser ? IssueSource::Browser : IssueSource::Server, $project->scrub_keys ?? [])),
                    'job' => $this->recordJobEventAction->execute($project, $event, $envelope),
                    'log' => $this->recordLogLineAction->execute($project, $event, $envelope),
                    'schedule' => $this->recordScheduleEventAction->execute($project, $event, $envelope),
                    'command' => $this->recordCommandEventAction->execute($project, $event, $envelope),
                    'health' => $this->recordReportedHealthAction->execute($project, $event, $envelope),
                    default => null,
                };
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $touched[(string) $type] = true;
        }

        $this->recordLogLineAction->flush();

        foreach (array_chunk($requests, self::INSERT_CHUNK) as $chunk) {
            DB::table('request_runs')->insert($chunk);
        }

        foreach (array_chunk($queries, self::INSERT_CHUNK) as $chunk) {
            DB::table('query_runs')->insert($chunk);
        }

        foreach (array_chunk($spans, self::INSERT_CHUNK) as $chunk) {
            DB::table('span_runs')->insert($chunk);
        }

        foreach (array_chunk($vitals, self::INSERT_CHUNK) as $chunk) {
            DB::table('web_vitals')->insert($chunk);
        }

        if ($counts !== []) {
            $this->recordRequestCountsAction->execute($project, $counts, $envelope);
        }

        $this->broadcastChanges($project, $touched);
    }

    /**
     * One page view's Core Web Vitals. A value out of range is dropped, not clamped:
     * a negative or minute-long LCP is a broken measurement, not a slow page.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function vitalRow(Project $project, array $event, Envelope $envelope): array
    {
        $micros = fn (string $key): ?int => isset($event[$key]) && is_numeric($event[$key]) && $event[$key] >= 0 && $event[$key] <= self::MAX_VITAL_MS
            ? Envelope::micros($event[$key])
            : null;
        $cls = isset($event['cls']) && is_numeric($event['cls']) && $event['cls'] >= 0 && $event['cls'] <= self::MAX_CLS ? round((float) $event['cls'], 4) : null;

        $row = [
            'lcp_us' => $micros('lcp_ms'),
            'inp_us' => $micros('inp_ms'),
            'ttfb_us' => $micros('ttfb_ms'),
            'fcp_us' => $micros('fcp_ms'),
            'cls' => $cls,
        ];

        if (array_filter($row, fn ($v): bool => $v !== null) === []) {
            throw new InvalidArgumentException('A vital without any value.');
        }

        $path = $event['route'] ?? parse_url((string) ($event['url'] ?? ''), PHP_URL_PATH);

        return [
            'project_id' => $project->id,
            'environment' => $envelope->environment,
            'release' => $envelope->release,
            'route' => mb_substr(self::normalizePath(is_string($path) ? $path : '/'), 0, 255),
            ...$row,
            'occurred_at' => Envelope::time($event['timestamp'] ?? null)->format('Y-m-d H:i:s.v'),
        ];
    }

    /**
     * /orders/42/items/0d9c... becomes /orders/{id}/items/{id}, so page views of one page group.
     */
    public static function normalizePath(string $path): string
    {
        $path = '/'.ltrim((string) preg_replace('~[?#].*~s', '', $path), '/');

        return (string) preg_replace('~(?<=/)(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?=/|$)~i', '{id}', $path);
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
            'route_name' => Envelope::short($event['route_name'] ?? null, 255),
            'domain' => Envelope::domain($event['domain'] ?? null, $event['url'] ?? null),
            // Signed URLs and reset links carry their secret in the query string.
            'url' => mb_substr($this->scrubber->scrubString((string) ($event['url'] ?? '')), 0, 2000),
            'status_code' => max(0, min(999, (int) ($event['status'] ?? 0))),
            'duration_us' => Envelope::micros($event['duration_ms'] ?? 0),
            'memory_peak' => isset($event['memory_peak']) && is_numeric($event['memory_peak']) ? (int) $event['memory_peak'] : null,
            ...Envelope::sampling($event),
            'occurred_at' => Envelope::time($event['timestamp'] ?? null)->format('Y-m-d H:i:s.v'),
        ];
    }

    /**
     * A cache read, outgoing call, mail, notification or Redis command on a trace.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function spanRow(Project $project, array $event, Envelope $envelope): array
    {
        $kind = SpanKind::tryFrom((string) ($event['kind'] ?? '')) ?? throw new InvalidArgumentException('Unknown span kind.');
        $attributes = is_array($event['attributes'] ?? null) ? array_slice($event['attributes'], 0, 20, true) : null;

        return [
            'project_id' => $project->id,
            'trace_id' => Envelope::short($event['trace_id'] ?? null),
            'environment' => $envelope->environment,
            'kind' => $kind->value,
            'name' => mb_substr((string) ($event['name'] ?? ''), 0, 64),
            'target' => mb_substr((string) ($event['target'] ?? ''), 0, 255),
            'duration_us' => isset($event['duration_ms']) && is_numeric($event['duration_ms']) ? Envelope::micros($event['duration_ms']) : null,
            'attributes' => $attributes !== null ? json_encode($attributes) : null,
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
            ...Envelope::sampling($event),
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

        if (isset($touched['request']) || isset($touched['request_counts']) || isset($touched['query']) || isset($touched['vital'])) {
            $kinds[] = ChangeKind::Performance;
        }

        // Once for all the batch's health results, not once per result.
        if (isset($touched['health'])) {
            HealthChecksQuery::forgetStates($project->id);
            $kinds[] = ChangeKind::Health;
        }

        foreach ($kinds as $kind) {
            $this->broadcast->execute($project->id, $kind, $project->uuid);
        }
    }
}
