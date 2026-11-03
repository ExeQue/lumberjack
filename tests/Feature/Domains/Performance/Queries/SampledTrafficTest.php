<?php

declare(strict_types=1);

use App\Domains\Overview\Queries\ProjectOverviewQuery;
use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Performance\Queries\QueryStatisticsQuery;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Performance\Support\RequestTraffic;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use Tests\Support\Performance\CreatesRequestCounts;

covers(RequestTraffic::class, RouteStatisticsQuery::class, ProjectOverviewQuery::class, QueryStatisticsQuery::class);

uses(CreatesRequestCounts::class);

describe('request totals', function () {
    it('counts requests from the SDK counts, not the sampled runs', function () {
        $project = Project::factory()->create();
        $this->createRequestCount($project, 95, durationUs: 9_500_000);
        $this->createRequestCount($project, 5, 500);
        RequestRun::factory()->for($project)->count(10)->create(['route' => '/orders', 'occurred_at' => now()->subMinutes(30), 'sample_rate' => 0.1]);

        $route = app(RouteStatisticsQuery::class)->get($project->id, Period::Day)[0];
        $overview = app(ProjectOverviewQuery::class)->get($project, Period::Day);

        expect($route)->toMatchArray(['route' => '/orders', 'requests' => 100, 'error_rate' => 0.05, 'total_us' => 10_500_000])
            ->and($route['statuses'])->toBe(['2xx' => 95, '3xx' => 0, '4xx' => 0, '5xx' => 5])
            ->and($overview['requests'])->toBe(100)
            ->and($overview['error_rate'])->toBe(0.05)
            ->and((int) array_sum(array_column($overview['request_series'], 'v')))->toBe(100);
    });

    it('weighs runs by their sample rate where no counts came in, and a run kept for being slow once', function () {
        $project = Project::factory()->create();
        RequestRun::factory()->for($project)->count(3)->create(['route' => '/orders', 'occurred_at' => now()->subHour(), 'sample_rate' => 0.25]);
        RequestRun::factory()->for($project)->create(['route' => '/orders', 'occurred_at' => now()->subHour(), 'sample_rate' => 0.25, 'sampled' => false, 'duration_us' => 3_000_000]);
        RequestRun::factory()->for($project)->create(['route' => '/legacy', 'occurred_at' => now()->subHour()]);

        $routes = collect(app(RouteStatisticsQuery::class)->get($project->id, Period::Day, sort: 'count'))->keyBy('route');

        expect($routes['/orders']['requests'])->toBe(13)
            ->and($routes['/legacy']['requests'])->toBe(1)
            ->and(app(ProjectOverviewQuery::class)->get($project, Period::Day)['requests'])->toBe(14);
    });

    it('counts runs before the first counted minute and the counts from then on, never both', function () {
        $project = Project::factory()->create();
        RequestRun::factory()->for($project)->count(4)->create(['route' => '/orders', 'occurred_at' => now()->subHours(3)]);
        $this->createRequestCount($project, 10, minute: now()->subHours(2)->startOfMinute());
        RequestRun::factory()->for($project)->count(2)->create(['route' => '/orders', 'occurred_at' => now()->subHour()]);

        expect(app(RouteStatisticsQuery::class)->get($project->id, Period::Day)[0]['requests'])->toBe(14);
    });
});

describe('percentiles', function () {
    it('leaves runs kept only for being slow out of the percentiles and the Apdex split', function () {
        $project = Project::factory()->create();
        $this->createRequestCount($project, 100);

        foreach ([100, 200, 300, 400] as $ms) {
            RequestRun::factory()->for($project)->create(['route' => '/orders', 'duration_us' => $ms * 1000, 'occurred_at' => now()->subMinutes(30), 'sample_rate' => 0.04]);
        }

        RequestRun::factory()->for($project)->count(5)->create(['route' => '/orders', 'duration_us' => 9_000_000, 'occurred_at' => now()->subMinutes(30), 'sample_rate' => 0.04, 'sampled' => false]);

        $route = app(RouteStatisticsQuery::class)->get($project->id, Period::Day)[0];
        $overview = app(ProjectOverviewQuery::class)->get($project, Period::Day);

        expect($route['p95'])->toBe(400_000)
            ->and($route['p99'])->toBe(400_000)
            ->and($route['apdex'])->toBe(1.0)
            ->and($overview['p99'])->toBe(400_000)
            ->and($overview['sample_rate'])->toBe(0.04);
    });

    it('says nothing is estimated when every run was kept', function () {
        $project = Project::factory()->create();
        RequestRun::factory()->for($project)->create(['occurred_at' => now()->subMinutes(5)]);

        expect(app(RouteStatisticsQuery::class)->sampleRate($project->id, Period::Day))->toBeNull();
    });
});

describe('queries', function () {
    it('weighs sampled calls by their rate, counts the rest once and takes the p95 from sampled calls only', function () {
        $project = Project::factory()->create();
        QueryRun::factory()->for($project)->count(2)->create(['duration_us' => 1_000, 'sample_rate' => 0.5]);
        QueryRun::factory()->for($project)->create(['duration_us' => 600_000, 'sample_rate' => 0.5, 'sampled' => false]);

        $stats = app(QueryStatisticsQuery::class)->get($project->id, Period::Day);

        expect($stats['calls'])->toBe(5)
            ->and($stats['total_us'])->toBe(604_000)
            ->and($stats['rows'][0]['calls'])->toBe(5)
            ->and($stats['rows'][0]['p95'])->toBe(1_000)
            ->and($stats['rows'][0]['max'])->toBe(600_000)
            ->and($stats['sample_rate'])->toBe(0.5);
    });
});
