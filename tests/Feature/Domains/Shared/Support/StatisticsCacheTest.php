<?php

declare(strict_types=1);

use App\Domains\Performance\Models\RequestRun;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\StatisticsCache;

covers(StatisticsCache::class);

describe('statistics cache', function () {
    it('serves a page\'s figures from the cache until they are no longer fresh', function () {
        $project = Project::factory()->create();
        $query = app(RouteStatisticsQuery::class);
        RequestRun::factory()->for($project)->create(['method' => 'GET', 'route' => '/a', 'occurred_at' => now()->subMinute()]);

        expect($query->get($project->id, Period::Hour)[0]['requests'])->toBe(1);

        RequestRun::factory()->for($project)->create(['method' => 'GET', 'route' => '/a', 'occurred_at' => now()->subMinute()]);

        expect($query->get($project->id, Period::Hour)[0]['requests'])->toBe(1)
            // Another sort, search or period is its own entry.
            ->and($query->get($project->id, Period::Hour, sort: 'count')[0]['requests'])->toBe(2)
            ->and($query->get($project->id, Period::Day)[0]['requests'])->toBe(2);

        $this->travel(StatisticsCache::ttl(Period::Hour)[1] + 1)->seconds();

        expect($query->get($project->id, Period::Hour)[0]['requests'])->toBe(2);
    });
});
