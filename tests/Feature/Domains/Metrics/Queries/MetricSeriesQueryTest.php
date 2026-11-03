<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Metrics\Data\MetricFilter;
use App\Domains\Metrics\Data\MetricWindow;
use App\Domains\Metrics\Enums\Metric;
use App\Domains\Metrics\Enums\MetricGroup;
use App\Domains\Metrics\Queries\MetricSeriesQuery;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Shared\Enums\Period;
use Tests\Support\Performance\CreatesRequestCounts;

covers(MetricSeriesQuery::class, MetricWindow::class);

uses(CreatesRequestCounts::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
    $this->project = Project::factory()->create();
    $this->day = MetricWindow::forPeriod(Period::Day);
    $this->value = fn (Metric $metric, array $filter = []): ?float => app(MetricSeriesQuery::class)->value($metric, new MetricFilter([$this->project->id], ...$filter), $this->day);
});

describe('request metrics', function () {
    it('weighs runs by their sample rate when a release is asked for, as the counts carry none', function () {
        $this->createRequestCount($this->project, 1000);
        RequestRun::factory()->for($this->project)->count(4)->create(['release' => 'v2', 'sample_rate' => 0.5, 'occurred_at' => now()->subHour()]);
        RequestRun::factory()->for($this->project)->create(['release' => 'v1', 'occurred_at' => now()->subHour()]);

        expect(($this->value)(Metric::Requests))->toEqual(1000)
            ->and(($this->value)(Metric::Requests, ['release' => 'v2']))->toEqual(8);
    });

    it('scores Apdex like the routes page', function () {
        $this->createRequestCount($this->project, 8);
        $this->createRequestCount($this->project, 2, 500);
        // Of the picked runs other than 5xx: two satisfied, one tolerated, one frustrated.
        foreach ([100_000, 200_000, 1_000_000, 5_000_000] as $duration) {
            RequestRun::factory()->for($this->project)->create(['duration_us' => $duration, 'occurred_at' => now()->subHour()]);
        }

        // 8 of 10 requests are not 5xx, scored (2 + 1/2) / 4.
        expect(($this->value)(Metric::Apdex))->toEqual(0.5);
    });

    it('matches a route pattern with a wildcard', function () {
        $this->createRequestCount($this->project, 3, route: '/api/orders');
        $this->createRequestCount($this->project, 4, route: '/api/users');
        $this->createRequestCount($this->project, 5, route: '/checkout');

        expect(($this->value)(Metric::Requests, ['route' => '/api/*']))->toEqual(7)
            ->and(($this->value)(Metric::Requests, ['route' => '/checkout']))->toEqual(5);
    });
});

describe('gauges', function () {
    it('adds up the latest size of each queue', function () {
        $sample = fn (string $queue, int $size, int $minutesAgo) => QueueSample::query()->create(['project_id' => $this->project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => $queue, 'size' => $size, 'sampled_at' => now()->subMinutes($minutesAgo)]);
        $sample('mail', 50, 10);
        $sample('mail', 5, 1);
        $sample('default', 3, 2);

        expect(($this->value)(Metric::QueueSize))->toEqual(8)
            ->and(($this->value)(Metric::QueueSize, ['queue' => 'mail']))->toEqual(5);
    });

    it('takes the busiest host as it reported last', function () {
        foreach ([[0.5, 0.95], [0.9, 0.2]] as [$old, $now]) {
            $agent = ProjectAgent::factory()->for($this->project)->create();
            HostSample::query()->create(['project_agent_id' => $agent->id, 'memory_used_bytes' => (int) ($old * 100), 'memory_total_bytes' => 100, 'sampled_at' => now()->subMinutes(10)]);
            HostSample::query()->create(['project_agent_id' => $agent->id, 'memory_used_bytes' => (int) ($now * 100), 'memory_total_bytes' => 100, 'sampled_at' => now()->subMinute()]);
        }

        expect(($this->value)(Metric::MemoryUsed))->toEqual(0.95);
    });
});

describe('issues and jobs', function () {
    it('counts occurrences, distinct users and new issues', function () {
        $issue = Issue::factory()->for($this->project)->create(['first_seen_at' => now()->subHour()]);
        Occurrence::factory()->for($issue)->count(3)->create(['user_id' => 'u1', 'occurred_at' => now()->subHour()]);
        Occurrence::factory()->for($issue)->create(['user_id' => 'u2', 'occurred_at' => now()->subHour()]);

        expect(($this->value)(Metric::Occurrences))->toEqual(4)
            ->and(($this->value)(Metric::Users))->toEqual(2)
            ->and(($this->value)(Metric::NewIssues))->toEqual(1);
    });

    it('counts failed job runs per queue', function () {
        JobRun::factory()->for($this->project)->failed()->count(2)->create(['queue' => 'mail', 'created_at' => now()->subHour()]);
        JobRun::factory()->for($this->project)->failed()->create(['queue' => 'default', 'created_at' => now()->subHour()]);
        JobRun::factory()->for($this->project)->create(['created_at' => now()->subHour()]);

        $series = app(MetricSeriesQuery::class)->series(Metric::JobFailures, new MetricFilter([$this->project->id], groupBy: MetricGroup::Queue), $this->day);

        expect(($this->value)(Metric::JobFailures))->toEqual(3)
            ->and(collect($series)->groupBy('series')->map->sum('value')->all())->toEqual(['mail' => 2, 'default' => 1]);
    });

    it('fills empty buckets of a count with zero and leaves other metrics out there', function () {
        $requests = app(MetricSeriesQuery::class)->series(Metric::Requests, new MetricFilter([$this->project->id]), $this->day);
        $p95 = app(MetricSeriesQuery::class)->series(Metric::P95, new MetricFilter([$this->project->id]), $this->day);

        expect($requests)->toHaveCount(24)
            ->and(collect($requests)->sum('value'))->toEqual(0)
            ->and($p95)->toBe([])
            ->and(($this->value)(Metric::P95))->toBeNull();
    });
});
