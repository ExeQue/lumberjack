<?php

declare(strict_types=1);

use App\Domains\Teams\Http\Controllers\TeamController;
use App\Domains\Teams\Http\Requests\StoreTeamRequest;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(TeamController::class, StoreTeamRequest::class);

describe('creating a team', function () {
    it('lets anyone create a team by default', function () {
        $user = User::factory()->inTeam()->create();

        $this->actingAs($user)->post(route('teams.store'), ['name' => 'Side project'])->assertRedirect();

        expect(Team::query()->where('name', 'Side project')->exists())->toBeTrue();
    });

    it('lets only administrators create a team when creation is limited to them', function () {
        config(['lumberjack.teams.allow_creation' => 'admins']);
        $user = User::factory()->inTeam()->create();

        $this->actingAs($user)->post(route('teams.store'), ['name' => 'Side project'])->assertForbidden();
        $this->actingAs($user)->get(route('team.show'))->assertInertia(fn (Assert $page) => $page->where('can_create_team', false));
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('teams.store'), ['name' => 'Platform'])->assertRedirect();

        expect(Team::query()->pluck('name')->all())->not->toContain('Side project')->toContain('Platform');
    });
});
