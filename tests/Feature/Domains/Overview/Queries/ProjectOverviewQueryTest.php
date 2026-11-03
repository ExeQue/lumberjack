<?php

declare(strict_types=1);

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Overview\Queries\ProjectOverviewQuery;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;

covers(ProjectOverviewQuery::class);

describe('the overview trend', function () {
    it('compares with the period just before', function () {
        $project = Project::factory()->create();
        RequestRun::factory()->for($project)->count(2)->create(['occurred_at' => now()->subHours(30)]);
        RequestRun::factory()->for($project)->count(3)->create(['occurred_at' => now()->subHours(2)]);

        $overview = app(ProjectOverviewQuery::class)->get($project, Period::Day);

        expect($overview['requests'])->toBe(3)
            ->and($overview['previous']['requests'])->toBe(2);
    });
});

describe('response times', function () {
    it('gives p50 and p95 per bucket, and nothing for a bucket without requests', function () {
        $project = Project::factory()->create();
        $at = now()->startOfHour()->subHours(2)->addMinutes(10);

        foreach ([10, 20, 30, 40, 1000] as $ms) {
            RequestRun::factory()->for($project)->create(['duration_us' => $ms * 1000, 'occurred_at' => $at]);
        }

        $series = collect(app(ProjectOverviewQuery::class)->get($project, Period::Day)['latency_series']);
        $bucket = $series->firstWhere('t', $at->copy()->startOfHour()->toAtomString());

        expect($bucket)->toMatchArray(['p50' => 30_000, 'p95' => 1_000_000])
            ->and($series->firstWhere('t', $at->copy()->startOfHour()->subHour()->toAtomString()))->toMatchArray(['p50' => null, 'p95' => null]);
    });
});

describe('what people change', function () {
    it('shows an issue resolved after the figures were cached', function () {
        $project = Project::factory()->create();
        $issue = Issue::factory()->for($project)->create();
        $query = app(ProjectOverviewQuery::class);

        expect($query->get($project, Period::Day)['open_issues'])->toBe(1);

        $issue->forceFill(['status' => IssueStatus::Resolved, 'resolved_at' => now()])->save();
        $overview = $query->get($project, Period::Day);

        expect($overview['open_issues'])->toBe(0)
            ->and($overview['latest_issues'])->toBe([]);
    });
});
