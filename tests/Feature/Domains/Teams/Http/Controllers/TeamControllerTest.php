<?php

declare(strict_types=1);

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Teams\Actions\SetTeamCurrencyAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Http\Controllers\TeamController;
use App\Domains\Teams\Http\Requests\SetTeamCurrencyRequest;
use App\Domains\Teams\Http\Requests\StoreTeamRequest;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Shared\ReadsQueryCounts;

covers(TeamController::class, StoreTeamRequest::class, SetTeamCurrencyRequest::class, SetTeamCurrencyAction::class);

uses(ReadsQueryCounts::class);

describe('creating a team', function () {
    beforeEach(fn () => config(['lumberjack.teams.allow_creation' => 'everyone']));

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

describe('the team\'s currency', function () {
    it('lets an administrator set it, and shares it with every page', function () {
        $user = User::factory()->inTeam()->create();

        $this->actingAs($user)->put(route('team.currency'), ['currency' => 'eur'])->assertRedirect();

        expect($user->currentTeam->refresh()->currency)->toBe('EUR');
        $this->actingAs($user)->get(route('team.show'))->assertInertia(fn (Assert $page) => $page->where('team.currency', 'EUR'));
    });

    it('refuses what is no currency code, and a member', function () {
        $team = Team::factory()->create();
        $admin = User::factory()->inTeam($team)->create();

        $this->actingAs($admin)->put(route('team.currency'), ['currency' => 'EURO'])->assertSessionHasErrors('currency');
        $this->actingAs(User::factory()->inTeam($team, TeamRole::Member)->create())->put(route('team.currency'), ['currency' => 'EUR'])->assertForbidden();

        expect($team->refresh()->currency)->toBe('USD');
    });
});
