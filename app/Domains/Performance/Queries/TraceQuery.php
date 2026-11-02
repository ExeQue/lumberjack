<?php

declare(strict_types=1);

namespace App\Domains\Performance\Queries;

use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Logs\Models\LogEntry;
use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestRun;

/**
 * Everything that happened under one trace id, in time order.
 */
class TraceQuery
{
    public const int LIMIT = 500;

    /**
     * @return array{requests: list<array<string, mixed>>, jobs: list<array<string, mixed>>, timeline: list<array<string, mixed>>}
     */
    public function get(int $projectId, string $traceId): array
    {
        $scope = fn ($q) => $q->where('project_id', $projectId)->where('trace_id', $traceId);

        $requests = RequestRun::query()->tap($scope)->orderBy('occurred_at')->limit(10)->get();
        $jobs = JobRun::query()->tap($scope)->orderBy('started_at')->limit(10)->get();

        $timeline = collect()
            ->concat(QueryRun::query()->tap($scope)->orderBy('occurred_at')->limit(self::LIMIT)->get()
                ->map(fn (QueryRun $q): array => ['type' => 'query', 'at' => $q->occurred_at->format('H:i:s.v'), 'duration_us' => $q->duration_us, 'connection' => $q->connection, 'sql' => $q->sql, 'location' => $q->location]))
            ->concat(LogEntry::query()->tap($scope)->with('channel')->orderBy('occurred_at')->limit(self::LIMIT)->get()
                ->map(fn (LogEntry $l): array => ['type' => 'log', 'at' => $l->occurred_at->format('H:i:s.v'), 'level' => $l->level->key(), 'channel' => $l->channel?->name, 'message' => $l->message]))
            ->concat(Occurrence::query()->tap($scope)->with('issue')->orderBy('occurred_at')->limit(50)->get()
                ->map(fn (Occurrence $o): array => ['type' => 'exception', 'at' => $o->occurred_at->format('H:i:s.v'), 'issue' => $o->issue?->uuid, 'title' => $o->issue?->title, 'message' => $o->issue?->message]))
            ->sortBy('at')
            ->values()
            ->all();

        return [
            'requests' => array_values($requests->map(fn (RequestRun $r): array => [
                'method' => $r->method, 'route' => $r->route, 'url' => $r->url, 'status' => $r->status_code,
                'duration_us' => $r->duration_us, 'at' => $r->occurred_at->toIso8601String(), 'host' => $r->host,
            ])->all()),
            'jobs' => array_values($jobs->map(fn (JobRun $j): array => [
                'name' => $j->name, 'status' => $j->status->value, 'attempt' => $j->attempt, 'duration_us' => $j->duration_us,
                'started_at' => $j->started_at?->toIso8601String(), 'host' => $j->host,
            ])->all()),
            'timeline' => array_values($timeline),
        ];
    }
}
