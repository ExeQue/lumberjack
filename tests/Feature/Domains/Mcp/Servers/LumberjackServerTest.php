<?php

declare(strict_types=1);

use App\Domains\Health\Actions\RecordHealthResultAction;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Servers\LumberjackServer;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Tests\Support\Mcp\CallsMcpTools;

covers(LumberjackServer::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->project = Project::factory()->for($this->team)->create(['slug' => 'shop']);
    $this->token = McpToken::factory()->for($this->team)->withToken('lj_mcp_secret')->create();
    $this->token->projects()->attach($this->project);
});

describe('authentication', function () {
    it('rejects an unknown token', function () {
        $this->callTool('nope', 'list_projects')->assertUnauthorized();
    });

    it('rejects an expired token', function () {
        $this->token->update(['expires_at' => now()->subMinute()]);

        $this->callTool('lj_mcp_secret', 'list_projects')->assertUnauthorized();
    });

    it('lists only the projects the token covers', function () {
        Project::factory()->for($this->team)->create(['slug' => 'other']);

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'list_projects')->assertOk());

        expect(collect($result['projects'])->pluck('slug')->all())->toBe(['shop']);
    });

    it('does not show an issue of a project outside the token', function () {
        $foreign = Issue::factory()->create();

        $this->callTool('lj_mcp_secret', 'get_issue', ['issue' => $foreign->uuid])
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    });
});

describe('the triage inbox', function () {
    it('holds the open issues until the agent writes a note on them', function () {
        [$first, $second] = Issue::factory()->for($this->project)->count(2)->create()->all();

        $before = $this->toolResult($this->callTool('lj_mcp_secret', 'triage_inbox')->assertOk());
        $this->callTool('lj_mcp_secret', 'add_issue_note', ['issue' => $first->uuid, 'body' => 'Triaged.'])->assertOk();
        $after = $this->toolResult($this->callTool('lj_mcp_secret', 'triage_inbox')->assertOk());

        expect(array_column($before['issues'], 'issue'))->toBe([$first->reference(), $second->reference()])
            ->and($before['has_more'])->toBeFalse()
            ->and(array_column($after['issues'], 'issue'))->toBe([$second->reference()])
            ->and(array_keys($before['issues'][0]))->not->toContain('latest_occurrence', 'repository', 'stacktrace');
    });

    it('leaves out issues in progress, resolved or ignored', function () {
        Issue::factory()->for($this->project)->create(['status' => 'in_progress']);
        Issue::factory()->for($this->project)->resolved()->create();
        Issue::factory()->for($this->project)->ignored()->create();

        expect($this->toolResult($this->callTool('lj_mcp_secret', 'triage_inbox')->assertOk())['issues'])->toBe([]);
    });

    it('brings a handled issue back when it surfaces again', function () {
        $issue = Issue::factory()->for($this->project)->create(['surfaced_at' => now()->subHour()]);
        $issue->notes()->create(['body' => 'Triaged.', 'mcp_token_id' => $this->token->id])->forceFill(['created_at' => now()->subMinutes(30)])->save();

        expect($this->toolResult($this->callTool('lj_mcp_secret', 'triage_inbox'))['issues'])->toBe([]);

        $issue->forceFill(['surfaced_at' => now()])->save();

        expect(array_column($this->toolResult($this->callTool('lj_mcp_secret', 'triage_inbox'))['issues'], 'issue'))->toBe([$issue->reference()]);
    });

    it('hands the agent the notes that tagged its token until it answers, with the tags written as names', function () {
        $issue = Issue::factory()->for($this->project)->create();
        $person = User::factory()->inTeam($this->team)->create(['name' => 'Sofie']);
        $other = McpToken::factory()->for($this->team)->create(['name' => 'other-bot']);
        $issue->notes()->create(['body' => "<@mcp:{$this->token->id}> note to self", 'mcp_token_id' => $this->token->id]);
        $issue->notes()->create(['body' => "<@mcp:{$this->token->id}> please find the deploy that broke this", 'user_id' => $person->id]);
        $issue->notes()->create(['body' => "<@mcp:{$other->id}> not for you", 'user_id' => $person->id]);


        $first = $this->toolResult($this->callTool('lj_mcp_secret', 'triage_inbox')->assertOk());
        $this->callTool('lj_mcp_secret', 'add_issue_note', ['issue' => $issue->uuid, 'body' => 'Release 2026.11.1 brought it in.'])->assertOk();
        $second = $this->toolResult($this->callTool('lj_mcp_secret', 'triage_inbox')->assertOk());

        expect($first['mentions'])->toHaveCount(1)
            ->and($first['mentions'][0]['author'])->toBe('Sofie')
            ->and($first['mentions'][0]['body'])->toBe('@'.$this->token->name.' (MCP) please find the deploy that broke this')
            ->and($first['mentions'][0]['issue'])->toBe($issue->reference())
            ->and($second['mentions'])->toBe([]);
    });
});

describe('health checks', function () {
    it('lists the checks with their state, and only those needing attention when asked', function () {
        $down = HealthCheck::factory()->for($this->project)->create(['slug' => 'disk:s3', 'degrade_after' => 1]);
        HealthCheck::factory()->for($this->project)->create(['slug' => 'cache:redis']);
        app(RecordHealthResultAction::class)->execute($down, new HealthResultData(HealthResultStatus::Failed, 'Not writable', host: 'web-1'));

        $all = $this->toolResult($this->callTool('lj_mcp_secret', 'list_health_checks')->assertOk());
        $attention = $this->toolResult($this->callTool('lj_mcp_secret', 'list_health_checks', ['attention' => true])->assertOk());
        $overview = $this->toolResult($this->callTool('lj_mcp_secret', 'get_project_overview')->assertOk());

        expect(collect($all['checks'])->pluck('state', 'slug')->all())->toBe(['cache:redis' => 'pending', 'disk:s3' => 'down'])
            ->and(collect($attention['checks'])->pluck('slug')->all())->toBe(['disk:s3'])
            ->and($attention['checks'][0]['last_message'])->toBe('Not writable')
            ->and($overview['health'])->toMatchArray(['total' => 2, 'down' => 1, 'pending' => 1]);
    });
});

describe('issue numbers', function () {
    it('takes an issue by its number as well as its id', function () {
        $issue = Issue::factory()->for($this->project)->create();

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'get_issue', ['issue' => 'SHOP-'.$issue->number])->assertOk());

        expect($result['id'])->toBe($issue->uuid)->and($result['reference'])->toBe('SHOP-'.$issue->number);
    });
});

describe('write tools', function () {
    it('sets a status with a comment, kept as a note by the agent', function () {
        $issue = Issue::factory()->for($this->project)->create();

        $this->callTool('lj_mcp_secret', 'set_issue_status', ['issue' => $issue->uuid, 'status' => 'ignored', 'note' => 'Bot traffic, nothing to fix.'])->assertOk();

        expect($issue->refresh()->status->value)->toBe('ignored')
            ->and($issue->notes()->sole()->mcp_token_id)->toBe($this->token->id);
    });

    it('resolves an issue and records a note via the token', function () {
        $issue = Issue::factory()->for($this->project)->create();

        $this->callTool('lj_mcp_secret', 'set_issue_status', ['issue' => $issue->uuid, 'status' => 'resolved'])->assertOk();
        $this->callTool('lj_mcp_secret', 'add_issue_note', ['issue' => $issue->uuid, 'body' => "Deadlock in StoreMediaAction.\nDetails."])->assertOk();

        expect($issue->refresh()->status)->toBe(IssueStatus::Resolved)
            ->and($issue->notes()->sole()->mcp_token_id)->toBe($this->token->id);
    });

    it('refuses writes with a read token', function () {
        $this->token->update(['scope' => 'read']);
        $issue = Issue::factory()->for($this->project)->create();

        $this->callTool('lj_mcp_secret', 'set_issue_status', ['issue' => $issue->uuid, 'status' => 'resolved'])
            ->assertJsonPath('result.isError', true);

        expect($issue->refresh()->status)->toBe(IssueStatus::Open);
    });

    test('every tool of the server is registered', function () {
        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer lj_mcp_secret', 'Accept' => 'application/json, text/event-stream']);

        expect($response->json('result.tools'))->toHaveCount(count(LumberjackServer::TOOLS));
    });
});
