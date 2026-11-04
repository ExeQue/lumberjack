<?php

declare(strict_types=1);

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Teams\Http\Controllers\TeamController;
use App\Domains\Teams\Http\Requests\StoreTeamRequest;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Shared\ReadsQueryCounts;

covers(TeamController::class, StoreTeamRequest::class);

uses(ReadsQueryCounts::class);

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

describe('the team page', function () {
    it('tells whether each MCP token is usable in the same few queries, however many there are', function () {
        $user = User::factory()->inTeam()->create();
        $page = fn () => $this->actingAs($user)->get(route('team.show'))->assertOk();
        McpToken::factory()->for($user->currentTeam)->for($user)->create();
        $one = $this->queriesRunBy($page);

        McpToken::factory()->for($user->currentTeam)->for($user)->count(3)->create();

        expect($this->queriesRunBy($page))->toBe($one);
        $page()->assertInertia(fn (Assert $page) => $page->has('tokens', 4)->where('tokens.0.usable', true));
    });
});
