<?php

declare(strict_types=1);

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Label;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\Issues\BulkUpdateIssues;
use App\Domains\Mcp\Tools\Issues\DeleteLabel;
use App\Domains\Mcp\Tools\Issues\ListLabels;
use App\Domains\Mcp\Tools\Issues\UpdateLabel;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Mcp\CallsMcpTools;

covers(ListLabels::class, BulkUpdateIssues::class, UpdateLabel::class, DeleteLabel::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_secret')
        ->allowing(McpArea::Issues, McpLevel::Manage)->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
    $this->label = Label::factory()->for($this->project)->create(['name' => 'payments']);
});

describe('labels', function () {
    it('lists the project\'s labels', function () {
        Issue::factory()->for($this->project)->labelled('payments')->create();

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'list_labels')->assertOk());

        expect($result['labels'])->toBe([['id' => $this->label->id, 'name' => 'payments', 'color' => 'blue', 'issues' => 1]]);
    });

    it('renames, recolors and deletes a label', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'update_label', ['label_id' => $this->label->id, 'name' => 'billing', 'color' => 'red'])->assertOk());
        expect($result)->toMatchArray(['name' => 'billing', 'color' => 'red']);

        $this->callTool('lj_mcp_secret', 'delete_label', ['label_id' => $this->label->id])->assertOk();
        expect(Label::query()->find($this->label->id))->toBeNull();
    });

    it('refuses a token below manage and a maker who may not change the project', function () {
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Write)]);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'delete_label', ['label_id' => $this->label->id])))->toBeTrue();

        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Manage)]);
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'update_label', ['label_id' => $this->label->id, 'name' => 'billing', 'color' => 'red'])))->toBeTrue()
            ->and($this->wasRefused($this->callTool('lj_mcp_secret', 'delete_label', ['label_id' => $this->label->id])))->toBeTrue()
            ->and($this->label->refresh()->name)->toBe('payments');
    });
});

describe('bulk updates', function () {
    it('resolves, assigns and labels many issues at once', function () {
        $issues = Issue::factory()->for($this->project)->count(2)->create();

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'bulk_update_issues', [
            'issues' => $issues->pluck('uuid')->all(),
            'status' => 'in_progress',
            'user_id' => $this->user->id,
            'add_label' => 'payments',
        ])->assertOk());

        expect($result['changed'])->toBe(['status' => 2, 'assignee' => 2, 'add_label' => 2]);
        $issues->each(fn (Issue $issue) => expect($issue->refresh())
            ->status->toBe(IssueStatus::InProgress)
            ->assigned_user_id->toBe($this->user->id)
            ->and($issue->labels->pluck('name')->all())->toBe(['payments']));
    });

    it('refuses issues of another project', function () {
        $elsewhere = Issue::factory()->create();

        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'bulk_update_issues', ['issues' => [$elsewhere->uuid], 'status' => 'resolved'])))->toBeTrue()
            ->and($elsewhere->refresh()->status)->toBe(IssueStatus::Open);
    });

    it('refuses a read token and a maker who may not see the project', function () {
        $issue = Issue::factory()->for($this->project)->create();
        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Read)]);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'bulk_update_issues', ['issues' => [$issue->uuid], 'status' => 'resolved'])))->toBeTrue();

        $this->token->update(['permissions' => McpPermissions::everywhere(McpLevel::Write)]);
        Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $ability === 'view' && ($arguments[0] ?? null) instanceof Project ? false : null);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'bulk_update_issues', ['issues' => [$issue->uuid], 'status' => 'resolved'])))->toBeTrue()
            ->and($issue->refresh()->status)->toBe(IssueStatus::Open);
    });
});
