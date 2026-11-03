<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\UnwatchIssue;
use App\Domains\Mcp\Tools\WatchIssue;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Tests\Support\Mcp\CallsMcpTools;

covers(WatchIssue::class, UnwatchIssue::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_secret')->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
    $this->issue = Issue::factory()->for($this->project)->create();
});

describe('watching over MCP', function () {
    it('makes the token\'s person watch and stop watching an issue', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'watch_issue', ['issue' => $this->issue->reference()])->assertOk());
        expect($result['watching'])->toBeTrue();

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'unwatch_issue', ['issue' => $this->issue->reference()])->assertOk());
        expect($result['watching'])->toBeFalse()
            ->and($this->issue->watchers()->count())->toBe(0);
    });

    it('refuses a read token and a token of nobody', function () {
        $this->token->update(['scope' => 'read']);
        expect($this->callTool('lj_mcp_secret', 'watch_issue', ['issue' => $this->issue->reference()])->json('result.isError'))->toBeTrue();

        // A token whose creator is gone does not authenticate at all.
        $this->token->update(['scope' => 'write', 'user_id' => null]);
        $this->callTool('lj_mcp_secret', 'watch_issue', ['issue' => $this->issue->reference()])->assertUnauthorized();
        expect($this->issue->watchers()->count())->toBe(0);
    });
});
