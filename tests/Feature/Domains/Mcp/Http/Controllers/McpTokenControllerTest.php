<?php

declare(strict_types=1);

use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Http\Controllers\McpTokenController;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;

covers(McpTokenController::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->other = Project::factory()->for($this->project->team)->create();
    $this->admin = User::factory()->inTeam($this->project->team, TeamRole::Admin)->create();
    $this->admin->update(['current_team_id' => $this->project->team_id]);
    $this->valid = [
        'name' => 'Claude',
        'projects' => [$this->project->uuid],
        'permissions' => ['issues' => ['level' => 'write', 'deny' => ['create_repo_issue']], 'telemetry' => ['level' => 'read']],
        'changes' => [$this->project->uuid => ['health' => ['level' => 'manage']]],
    ];
});

describe('making a token', function () {
    it('saves Default, single-tool changes and per-project changes', function () {
        $this->actingAs($this->admin)->post(route('mcp-tokens.store'), $this->valid)->assertRedirect()->assertSessionHas('secret');

        $token = McpToken::query()->sole();

        expect($token->permissions->level(McpArea::Issues))->toBe(McpLevel::Write)
            ->and($token->permissions->level(McpArea::Screens))->toBe(McpLevel::None)
            ->and($token->permits('create_repo_issue', $this->project))->toBeFalse()
            ->and($token->levelFor(McpArea::Health, $this->project))->toBe(McpLevel::Manage)
            ->and($token->projects()->pluck('projects.id')->all())->toBe([$this->project->id]);
    });

    it('covers every project, keeping only rows for projects with changes', function () {
        $this->actingAs($this->admin)->post(route('mcp-tokens.store'), [...$this->valid, 'all_projects' => true, 'projects' => []])->assertRedirect()->assertSessionHasNoErrors();

        $token = McpToken::query()->sole();

        expect($token->all_projects)->toBeTrue()
            ->and($token->readableProjects())->toHaveCount(2)
            ->and($token->projects()->pluck('projects.id')->all())->toBe([$this->project->id]);
    });

    it('refuses what an area does not offer', function (array $change, string $field) {
        $this->actingAs($this->admin)->post(route('mcp-tokens.store'), array_replace_recursive($this->valid, $change))->assertSessionHasErrors($field);

        expect(McpToken::query()->count())->toBe(0);
    })->with([
        'an unknown area' => [['permissions' => ['billing' => ['level' => 'read']]], 'permissions.billing'],
        'a level the area lacks' => [['permissions' => ['telemetry' => ['level' => 'write']]], 'permissions.telemetry.level'],
        'a tool of another area' => [['permissions' => ['issues' => ['grant' => ['update_screen']]]], 'permissions.issues.grant'],
    ]);

    it('answers a malformed permission with a validation error', function () {
        $this->actingAs($this->admin)->post(route('mcp-tokens.store'), [...$this->valid, 'permissions' => ['issues' => ['level' => ['write'], 'grant' => [['x']]]]])
            ->assertSessionHasErrors(['permissions.issues.level', 'permissions.issues.grant.0']);
    });

    it('refuses a team-wide area changed for one project', function () {
        $this->actingAs($this->admin)->post(route('mcp-tokens.store'), [...$this->valid, 'changes' => [$this->project->uuid => ['screens' => ['level' => 'write']]]])
            ->assertSessionHasErrors('changes.'.$this->project->uuid.'.screens');
    });

    it('is for team admins only', function () {
        $member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create(['current_team_id' => $this->project->team_id]);

        $this->actingAs($member)->post(route('mcp-tokens.store'), $this->valid)->assertForbidden();
    });
});

describe('changing a token', function () {
    it('changes what it may do, keeps its secret and acts as the editor from then on', function () {
        $owner = User::factory()->inTeam($this->project->team, TeamRole::Admin)->create();
        $token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_kept')->create(['user_id' => $owner->id]);
        $hash = $token->token_hash;

        $this->actingAs($this->admin)->put(route('mcp-tokens.update', $token), [...$this->valid, 'name' => 'Renamed', 'projects' => [$this->other->uuid], 'changes' => []])->assertRedirect()->assertSessionHasNoErrors();

        $token->refresh();

        expect($token->name)->toBe('Renamed')
            ->and($token->token_hash)->toBe($hash)
            ->and($token->user_id)->toBe($this->admin->id)
            ->and($token->projects()->pluck('projects.id')->all())->toBe([$this->other->id])
            ->and($token->permissions->level(McpArea::Issues))->toBe(McpLevel::Write);
    });

    it('is refused for a token of another team', function () {
        $token = McpToken::factory()->create();

        $this->actingAs($this->admin)->put(route('mcp-tokens.update', $token), $this->valid)->assertForbidden();
    });
});
