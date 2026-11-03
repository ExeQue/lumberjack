<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Projects\Models\Project;
use App\Domains\Releases\Http\Controllers\ReleaseController;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(ReleaseController::class);

describe('the releases page', function () {
    it('lists the releases and the new issues of the selected one', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();
        $issue = Issue::factory()->for($project)->create(['first_seen_at' => now()->subHour(), 'title' => 'Card declined']);
        Occurrence::factory()->for($issue)->create(['release' => 'v3', 'occurred_at' => now()->subHour()]);

        $this->actingAs($user)
            ->get(route('releases.index', ['project' => $project, 'release' => 'v3']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('releases/index')
                ->where('releases.0.release', 'v3')
                ->where('releases.0.new_issues', 1)
                ->where('newIssues.0.title', 'Card declined'));
    });

    it('is closed to someone outside the team', function () {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->inTeam()->create())
            ->get(route('releases.index', $project))
            ->assertForbidden();
    });
});
