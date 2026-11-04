<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Support\McpContext;
use App\Domains\Metrics\Support\MetricsCredential;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Mcp\CallsMcpTools;

covers(McpToken::class, McpContext::class, MetricsCredential::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_secret')->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
    $this->issue = Issue::factory()->for($this->project)->create();
});

describe('what a token reads', function () {
    it('reads the projects picked for it while they stay in its team', function () {
        expect($this->token->readableProjects()->modelKeys())->toBe([$this->project->id]);

        $this->project->update(['team_id' => Team::factory()->create()->id]);

        expect($this->token->readableProjects())->toBeEmpty();
        expect($this->toolResult($this->callTool('lj_mcp_secret', 'list_projects'))['projects'])->toBe([]);
    });

    it('reads only what its maker may view, through the same policies as the app', function () {
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'view' ? false : null);

        expect($this->token->readableProjects())->toBeEmpty();
        $this->withToken('lj_mcp_secret')->getJson('/api/v1/projects')->assertOk()->assertExactJson(['projects' => []]);
    });
});

describe('what a token changes', function () {
    it('changes an issue only when its maker may update it', function () {
        $this->callTool('lj_mcp_secret', 'set_issue_status', ['issue' => $this->issue->reference(), 'status' => 'resolved'])
            ->assertOk()->assertJsonPath('result.isError', false);

        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);

        $this->callTool('lj_mcp_secret', 'set_issue_status', ['issue' => $this->issue->reference(), 'status' => 'open'])
            ->assertOk()->assertJsonPath('result.isError', true);
        expect($this->issue->refresh()->status->value)->toBe('resolved');
    });

    it('still lets its maker watch an issue they may only view', function () {
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);

        expect($this->toolResult($this->callTool('lj_mcp_secret', 'watch_issue', ['issue' => $this->issue->reference()])->assertOk())['watching'])->toBeTrue();
    });
});
