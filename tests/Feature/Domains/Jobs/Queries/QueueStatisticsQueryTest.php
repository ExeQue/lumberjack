<?php

declare(strict_types=1);

use App\Domains\Jobs\Models\JobRun;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Jobs\Queries\QueueStatisticsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use Tests\Support\Shared\ReadsQueryCounts;

covers(QueueStatisticsQuery::class);

uses(ReadsQueryCounts::class);

describe('queue statistics', function () {
    it('takes the latest sample of a queue across hosts, not their sum, with the p95 wait of its runs', function () {
        // One instant for every timestamp: two now() calls apart by microseconds shift the wait.
        $this->freezeTime();
        $project = Project::factory()->create();
        $sample = fn (string $host, int $size, int $minutesAgo, string $queue = 'default'): QueueSample => QueueSample::query()->create([
            'project_id' => $project->id, 'host' => $host, 'connection' => 'redis', 'queue' => $queue, 'size' => $size, 'oldest_age_s' => $size * 2, 'sampled_at' => now()->subMinutes($minutesAgo),
        ]);
        $sample('web-1', 40, 3);
        $sample('web-2', 55, 1);
        $sample('web-1', 50, 2);
        JobRun::factory()->for($project)->create(['queue' => 'emails', 'queued_at' => now()->subSeconds(30), 'started_at' => now()->subSeconds(10)]);

        $stats = app(QueueStatisticsQuery::class)->get($project->id, Period::Hour);

        expect($stats)->toHaveCount(2)
            ->and($stats[0])->toMatchArray(['connection' => 'redis', 'queue' => 'default', 'size' => 55, 'oldest_age_s' => 110, 'p95_wait' => null])
            ->and(max(array_column($stats[0]['series'], 'v')))->toEqual(55)
            ->and($stats[1])->toMatchArray(['queue' => 'emails', 'size' => null, 'p95_wait' => 20_000_000, 'series' => []]);
    });

    it('reads the series of every queue in one query', function () {
        $project = Project::factory()->create();
        $statistics = fn () => app(QueueStatisticsQuery::class)->get($project->id, Period::Hour);
        QueueSample::factory()->forProject($project)->forQueue('queue-0')->waiting(3)->create();
        $one = $this->queriesRunBy($statistics);

        foreach (['queue-1', 'queue-2', 'queue-3'] as $queue) {
            QueueSample::factory()->forProject($project)->forQueue($queue)->waiting(7)->create();
        }

        expect($this->queriesRunBy($statistics))->toBe($one)
            ->and(array_map(fn (array $queue): float => max(array_column($queue['series'], 'v')), $statistics()))->toEqual([3, 7, 7, 7]);
    });
});
