<?php

declare(strict_types=1);

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Models\AdminAuditEntry;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueWatcher;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Actions\MoveProjectToTeamAction;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Policies\ProjectPolicy;
use App\Domains\Screens\Data\WidgetScope;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Ingest\BuildsIngestPayloads;

covers(ProjectController::class, MoveProjectToTeamAction::class, ProjectPolicy::class);

uses(BuildsIngestPayloads::class);

beforeEach(function () {
    $this->project = Project::factory()->withToken('lj_srv_move-token')->create(['slug' => 'shop', 'issue_prefix' => 'SHOP']);
    $this->from = $this->project->team;
    $this->to = Team::factory()->create(['name' => 'Agency']);
    $this->user = User::factory()->inTeam($this->from, TeamRole::Admin)->create();
    $this->to->members()->attach($this->user, ['role' => TeamRole::Owner->value]);
    $this->move = fn (array $input = []) => $this->actingAs($this->user)
        ->put(route('projects.move', $this->project), [...['team' => $this->to->uuid, 'slug' => 'shop', 'issue_prefix' => 'SHOP'], ...$input]);
});

describe('moving a project', function () {
    it('moves it with its data, and its ingest token keeps working', function () {
        $issue = Issue::factory()->for($this->project)->create();
        Occurrence::factory()->for($issue)->create(['project_id' => $this->project->id]);

        ($this->move)()->assertSessionHasNoErrors()->assertRedirect(route('projects.settings', $this->project));

        expect($this->project->refresh()->team_id)->toBe($this->to->id)
            ->and(Issue::query()->where('project_id', $this->project->id)->count())->toBe(1)
            ->and(Occurrence::query()->where('project_id', $this->project->id)->count())->toBe(1);

        $this->postJson('/api/ingest', $this->batch([$this->exceptionEvent()]), ['Authorization' => 'Bearer lj_srv_move-token'])->assertStatus(202);
    });

    it('is logged once, naming who moved it and both teams', function () {
        ($this->move)();

        $entry = AdminAuditEntry::query()->where('action', AdminAction::ProjectMoved)->sole();
        expect($entry->user_id)->toBe($this->user->id)
            ->and($entry->data)->toMatchArray(['project' => $this->project->uuid, 'from' => $this->from->name, 'to' => 'Agency']);
    });
});

describe('who may move it', function () {
    it('forbids a member of the source team', function () {
        $this->from->members()->updateExistingPivot($this->user->id, ['role' => TeamRole::Member->value]);

        ($this->move)()->assertForbidden();
        expect($this->project->refresh()->team_id)->toBe($this->from->id);
    });

    it('forbids an admin of the source team who does not administer the target', function () {
        $this->to->members()->updateExistingPivot($this->user->id, ['role' => TeamRole::Member->value]);

        ($this->move)()->assertForbidden();
        expect($this->project->refresh()->team_id)->toBe($this->from->id);
    });

    it('offers only the other teams the user administers', function () {
        Team::factory()->create()->members()->attach($this->user, ['role' => TeamRole::Member->value]);

        $this->actingAs($this->user)->get(route('projects.settings', $this->project))
            ->assertInertia(fn ($page) => $page->where('move_targets', [['uuid' => $this->to->uuid, 'name' => 'Agency', 'slug' => 'shop', 'issue_prefix' => 'SHOP']]));
    });
});

describe('a slug the target team has already', function () {
    beforeEach(fn () => Project::factory()->for($this->to)->create(['slug' => 'shop', 'issue_prefix' => 'SHOP']));

    it('is suggested with a number', function () {
        $this->actingAs($this->user)->get(route('projects.settings', $this->project))
            ->assertInertia(fn ($page) => $page->where('move_targets.0.slug', 'shop-2')->where('move_targets.0.issue_prefix', 'SHOP2'));
    });

    it('is rejected', function () {
        ($this->move)()->assertSessionHasErrors(['slug', 'issue_prefix']);
        expect($this->project->refresh()->team_id)->toBe($this->from->id);
    });

    it('moves under a new one', function () {
        ($this->move)(['slug' => 'shop-2', 'issue_prefix' => 'shop2'])->assertSessionHasNoErrors();

        expect($this->project->refresh())->team_id->toBe($this->to->id)->slug->toBe('shop-2')->issue_prefix->toBe('SHOP2');
    });
});

describe('alerts', function () {
    it('takes its own channels along and lets go of the old team\'s rules and channels', function () {
        $own = NotificationChannel::factory()->create(['team_id' => $this->from->id, 'project_id' => $this->project->id]);
        $shared = NotificationChannel::factory()->create(['team_id' => $this->from->id]);
        $teamRule = AlertRule::query()->where('team_id', $this->from->id)->firstOrFail();
        $ownRule = AlertRule::factory()->regression()->for($this->project)->create();
        $teamRule->addOnsFor($this->project)->attach($own);
        $ownRule->channels()->attach([$own->id, $shared->id]);

        ($this->move)()->assertSessionHasNoErrors();

        expect($own->refresh()->team_id)->toBe($this->to->id)
            ->and($shared->refresh()->team_id)->toBe($this->from->id)
            ->and(DB::table('project_alert_rule_channel')->where('project_id', $this->project->id)->exists())->toBeFalse()
            ->and($ownRule->channels()->pluck('notification_channels.id')->all())->toBe([$own->id]);
    });
});

describe('MCP tokens', function () {
    it('lose the project', function () {
        $token = McpToken::factory()->create(['team_id' => $this->from->id, 'all_projects' => false]);
        $token->projects()->attach($this->project);

        ($this->move)();

        expect($token->projects()->exists())->toBeFalse();
    });
});

describe('screens of the old team', function () {
    it('drop the project, and a scope left empty shows nothing rather than everything', function () {
        $other = Project::factory()->for($this->from)->create();
        $widget = fn (string $id, array $projects): array => ['id' => $id, 'type' => 'stat', 'source' => 'requests', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'scope' => ['projects' => $projects]];
        $shared = Screen::factory()->scopedTo($this->project, $other)->create(['team_id' => $this->from->id, 'widgets' => [$widget('a', [$this->project->uuid]), $widget('b', [$this->project->uuid, $other->uuid]), $widget('c', [])]]);
        $alone = Screen::factory()->scopedTo($this->project)->create(['team_id' => $this->from->id]);
        $untouched = Screen::factory()->scopedTo($other)->create(['team_id' => $this->from->id]);
        $revision = $untouched->revision;

        ($this->move)()->assertSessionHasNoErrors();

        $widgets = $shared->refresh()->widgets;
        expect($shared->scope['projects'])->toBe([$other->uuid])
            ->and($widgets[0]['scope']['projects'])->toBe([WidgetScope::NO_PROJECT])
            ->and($widgets[1]['scope']['projects'])->toBe([$other->uuid])
            ->and($widgets[2]['scope']['projects'])->toBe([])
            ->and($alone->refresh()->scope['projects'])->toBe([WidgetScope::NO_PROJECT])
            ->and($untouched->refresh()->revision)->toBe($revision);
    });

    it('still save as they are after it', function () {
        $widget = ['id' => 'a', 'type' => 'stat', 'source' => 'requests', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'scope' => ['projects' => [$this->project->uuid]]];
        $screen = Screen::factory()->scopedTo($this->project)->create(['team_id' => $this->from->id, 'widgets' => [$widget]]);

        ($this->move)();
        $screen->refresh();

        $this->actingAs($this->user)->put(route('screens.update', $screen), ['scope' => $screen->scope])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->putJson(route('screens.layout', $screen), ['cols' => 4, 'rows' => 4, 'widgets' => $screen->widgets])->assertSuccessful();
    });
});

describe('people on its issues', function () {
    it('clears assignees and watchers who are not in the new team, and keeps those who are', function () {
        $stays = User::factory()->inTeam($this->to, TeamRole::Member)->create();
        $leaves = User::factory()->inTeam($this->from, TeamRole::Member)->create();
        $kept = Issue::factory()->for($this->project)->create(['assigned_user_id' => $stays->id]);
        $cleared = Issue::factory()->for($this->project)->create(['assigned_user_id' => $leaves->id]);
        IssueWatcher::factory()->create(['issue_id' => $kept->id, 'user_id' => $stays->id]);
        IssueWatcher::factory()->create(['issue_id' => $kept->id, 'user_id' => $leaves->id]);

        ($this->move)()->assertSessionHasNoErrors();

        expect($kept->refresh()->assigned_user_id)->toBe($stays->id)
            ->and($cleared->refresh()->assigned_user_id)->toBeNull()
            ->and(IssueWatcher::query()->where('issue_id', $kept->id)->pluck('user_id')->all())->toBe([$stays->id]);
    });
});
