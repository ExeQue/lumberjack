<?php

declare(strict_types=1);

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Ingest\Actions\ProcessIngestBatchAction;
use App\Domains\Logs\Models\LogEntry;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ingest\BuildsIngestPayloads;

uses(BuildsIngestPayloads::class);

covers(ProcessIngestBatchAction::class);

describe('ingest batch cost', function () {
    it('stores a busy batch in a bounded number of queries', function () {
        $project = Project::factory()->create();
        $at = now()->getTimestamp();
        $events = [
            ...array_map(fn (int $i): array => ['type' => 'log', 'timestamp' => $at + $i / 1000, 'channel' => 'default', 'level' => 'info', 'message' => "Line {$i}"], range(1, 100)),
            ['type' => 'log', 'timestamp' => $at + 0.5, 'channel' => 'default', 'level' => 'error', 'message' => 'It broke'],
            ...array_map(fn (int $i): array => ['type' => 'request', 'method' => 'GET', 'route' => '/a', 'url' => '/a', 'status' => 200, 'duration_ms' => 12, 'timestamp' => $at], range(1, 20)),
            ...array_map(fn (int $i): array => ['type' => 'query', 'sql' => 'select 1', 'duration_ms' => 1, 'timestamp' => $at], range(1, 20)),
            ...array_map(fn (int $i): array => ['type' => 'health', 'slug' => 'database:pgsql', 'status' => 'ok', 'timestamp' => $at + $i], range(1, 5)),
        ];

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        app(ProcessIngestBatchAction::class)->execute($project, $this->batch($events));
        $stored = $queries;

        // 165 before the lines were bulk inserted and the health check looked up once per
        // batch; what is left is mostly the per-host health state, one result at a time.
        expect($stored)->toBeLessThanOrEqual(60)
            ->and(LogEntry::query()->where('project_id', $project->id)->orderBy('id')->pluck('message')->all())
            ->toBe([...array_map(fn (int $i): string => "Line {$i}", range(1, 100)), 'It broke'])
            ->and(HealthCheck::query()->where('project_id', $project->id)->count())->toBe(1);
    });
});
