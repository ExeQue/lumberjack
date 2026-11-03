<?php

declare(strict_types=1);

use App\Domains\Issues\Http\Controllers\IssueController;
use App\Domains\Issues\Models\Issue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Teams\CreatesTeamMembers;

covers(IssueController::class);

uses(CreatesTeamMembers::class);

describe('one issue', function () {
    it('charts its occurrences over the chosen period, the last 24 hours by default', function (?string $period, string $shown, int $buckets) {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $issue = Issue::factory()->for($project)->create();

        $this->actingAs($user)->get(route('issues.show', [$project, $issue, 'period' => $period]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('issues/show')
                ->where('period', $shown)
                ->has('series', fn ($series) => $series->etc())
                ->where('series', fn ($series) => count($series) >= $buckets && count($series) <= $buckets + 1));
    })->with([
        'default' => [null, '24h', 24],
        'a week' => ['7d', '7d', 28],
        'unknown' => ['1y', '24h', 24],
    ]);
});
