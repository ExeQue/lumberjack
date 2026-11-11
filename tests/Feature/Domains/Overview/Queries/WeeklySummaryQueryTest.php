<?php

declare(strict_types=1);

use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthOutage;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueEvent;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Overview\Queries\WeeklySummaryQuery;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

covers(WeeklySummaryQuery::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
    $this->project = Project::factory()->create(['name' => 'Shop']);
    $this->summary = fn (): ?array => app(WeeklySummaryQuery::class)->get($this->project);
});

describe('a project\'s week', function () {
    it('is null when nothing came in all week', function () {
        // Only the week before.
        RequestRun::factory()->for($this->project)->create(['occurred_at' => now()->subDays(10)]);

        expect(($this->summary)())->toBeNull();
    });

    it('compares traffic, errors and speed with the week before', function () {
        RequestRun::factory()->for($this->project)->forRoute('GET', '/orders')->count(3)->create(['occurred_at' => now()->subDay(), 'duration_us' => 200_000]);
        RequestRun::factory()->for($this->project)->forRoute('GET', '/orders')->create(['occurred_at' => now()->subDay(), 'duration_us' => 400_000, 'status_code' => 500]);
        RequestRun::factory()->for($this->project)->forRoute('GET', '/orders')->count(2)->create(['occurred_at' => now()->subDays(9), 'duration_us' => 100_000]);

        $summary = ($this->summary)();

        expect($summary['project']['name'])->toBe('Shop')
            ->and($summary['requests'])->toBe(['current' => 4, 'previous' => 2])
            ->and($summary['error_rate'])->toBe(['current' => 0.25, 'previous' => 0.0])
            ->and($summary['p95_ms'])->toBe(['current' => 400.0, 'previous' => 100.0])
            ->and($summary['apdex']['current'])->toBeLessThan($summary['apdex']['previous'])
            ->and($summary['slower_routes'])->toBe([['method' => 'GET', 'route' => '/orders', 'p95_ms' => 400.0, 'previous_p95_ms' => 100.0]]);
    });

    it('lists new issues, regressions and the most frequent issues', function () {
        $often = Issue::factory()->for($this->project)->create(['title' => 'Often', 'first_seen_at' => now()->subDays(2), 'first_release' => 'v2']);
        $once = Issue::factory()->for($this->project)->create(['title' => 'Once', 'first_seen_at' => now()->subDays(2), 'first_release' => 'v2']);
        $old = Issue::factory()->for($this->project)->create(['title' => 'Old', 'first_seen_at' => now()->subDays(8)]);
        Occurrence::factory()->for($often)->count(3)->create(['occurred_at' => now()->subDay(), 'release' => 'v2']);
        Occurrence::factory()->for($once)->create(['occurred_at' => now()->subDay(), 'release' => 'v2']);
        Occurrence::factory()->for($old)->create(['occurred_at' => now()->subDays(8)]);
        IssueEvent::factory()->for($old)->regressed('v2')->create(['occurred_at' => now()->subDay()]);
        DB::table('project_releases')->insert([
            ['project_id' => $this->project->id, 'release' => 'v1', 'first_seen_at' => now()->subDays(9)],
            ['project_id' => $this->project->id, 'release' => 'v2', 'first_seen_at' => now()->subDays(3)],
            ['project_id' => $this->project->id, 'release' => 'v3', 'first_seen_at' => now()->subDays(1)],
        ]);

        $summary = ($this->summary)();

        expect($summary['new_issues'])->toBe(['current' => 2, 'previous' => 1])
            ->and($summary['regressions'])->toBe(['current' => 1, 'previous' => 0])
            ->and(array_column($summary['top_issues'], 'occurrences', 'title'))->toBe(['Often' => 3, 'Once' => 1])
            ->and(array_column($summary['releases'], 'new_issues', 'release'))->toBe(['v2' => 2, 'v3' => 0])
            ->and($summary['releases'][0]['regressions'])->toBe(1)
            ->and($summary['most_new_issues'])->toBe(['release' => 'v2', 'new_issues' => 2]);
    });

    it('counts outages, maintenance, failed jobs and missed tasks', function () {
        $check = HealthCheck::factory()->for($this->project)->monitored()->create(['last_result_at' => now(), 'created_at' => now()->subDays(30)]);
        HealthOutage::factory()->of($check)->create(['status' => HealthStatus::Down, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour()]);
        MaintenanceWindow::factory()->of($this->project)->create(['message' => 'Upgrade', 'starts_at' => now()->subDays(2), 'expected_end_at' => now()->subDays(2)->addMinutes(30), 'ended_at' => now()->subDays(2)->addMinutes(45)]);
        JobRun::factory()->for($this->project)->failed()->create(['created_at' => now()->subDay()]);
        JobRun::factory()->for($this->project)->failed()->count(2)->create(['created_at' => now()->subDays(9)]);
        $task = ScheduledTask::factory()->for($this->project)->create();
        ScheduledTaskRun::factory()->for($task, 'task')->missed()->create(['started_at' => now()->subDay()]);

        $summary = ($this->summary)();

        expect($summary['outages'])->toMatchArray(['current' => 1, 'previous' => 0, 'down' => 1])
            ->and($summary['outages']['listed'][0])->toMatchArray(['check' => 'Website', 'status' => 'down'])
            ->and($summary['health_checks'])->toHaveCount(1)
            ->and($summary['health_checks'][0]['uptime'])->toBeLessThan(1)
            ->and($summary['maintenance'])->toHaveCount(1)
            ->and($summary['maintenance'][0])->toMatchArray(['message' => 'Upgrade', 'overrun_minutes' => 15])
            ->and($summary['failed_jobs'])->toBe(['current' => 1, 'previous' => 2])
            ->and($summary['missed_tasks'])->toBe(['current' => 1, 'previous' => 0]);
    });
});
