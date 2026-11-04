<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Http\Middleware\UseTabTeam;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(UseTabTeam::class);

beforeEach(function () {
    $this->home = Team::factory()->create(['name' => 'Home']);
    $this->other = Team::factory()->create(['name' => 'Other']);
    $this->user = User::factory()->inTeam($this->home)->create();
    $this->other->members()->attach($this->user, ['role' => 'member']);
});

describe('a tab of its own team', function () {
    it('shows the team the tab sends, without changing the stored one', function () {
        $this->actingAs($this->user)
            ->withHeader(UseTabTeam::HEADER, $this->other->uuid)
            ->get(route('projects.index'))
            ->assertHeader('Vary')
            ->assertInertia(fn (Assert $page) => $page->where('team.uuid', $this->other->uuid));

        expect($this->user->refresh()->current_team_id)->toBe($this->home->id);

        // A request of its own loads the user afresh, as in the app.
        $this->flushHeaders()->actingAs($this->user->fresh())->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('team.uuid', $this->home->uuid));
    });

    it('ignores a team the user is not in', function () {
        $foreign = Team::factory()->create();

        $this->actingAs($this->user)
            ->withHeader(UseTabTeam::HEADER, $foreign->uuid)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('team.uuid', $this->home->uuid));
    });

    it('shows a picked team right after the switch, whatever the tab sent', function () {
        $this->actingAs($this->user)
            ->withHeader(UseTabTeam::HEADER, $this->home->uuid)
            ->put(route('teams.switch', $this->other))
            ->assertRedirect(route('projects.index'));

        $this->actingAs($this->user->fresh())
            ->withHeader(UseTabTeam::HEADER, $this->home->uuid)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('team.uuid', $this->other->uuid));
    });

    it('keeps the tab\'s last project in its sidebar on team pages', function () {
        Project::factory()->for($this->home)->create(['name' => 'Alpha']);
        $beta = Project::factory()->for($this->home)->create(['name' => 'Beta']);

        $this->actingAs($this->user)
            ->withHeader(UseTabTeam::HEADER, $this->home->uuid)
            ->withHeader('X-Lumberjack-Project', $beta->uuid)
            ->get(route('team.show'))
            ->assertInertia(fn (Assert $page) => $page->where('sidebar_project.uuid', $beta->uuid));
    });
});
