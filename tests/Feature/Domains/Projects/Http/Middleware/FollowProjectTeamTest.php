<?php

declare(strict_types=1);

use App\Domains\Projects\Http\Middleware\FollowProjectTeam;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

covers(FollowProjectTeam::class);

describe('opening a project in another team', function () {
    it('makes that team the current one, so the sidebar and breadcrumbs match', function () {
        $other = Team::factory()->create(['name' => 'Other team']);
        $user = User::factory()->inTeam()->inTeam($other, TeamRole::Member)->create();
        $project = Project::factory()->create(['team_id' => $other->id]);
        $user->forceFill(['current_team_id' => $user->teams()->whereKeyNot($other->id)->value('teams.id')])->save();

        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('team.name', 'Other team'));

        expect($user->fresh()->current_team_id)->toBe($other->id);
    });

    it('leaves the current team alone for a project the user cannot see', function () {
        $user = User::factory()->inTeam()->create();
        $current = $user->current_team_id;

        $this->actingAs($user)->get(route('projects.show', Project::factory()->create()))->assertForbidden();

        expect($user->fresh()->current_team_id)->toBe($current);
    });
});
