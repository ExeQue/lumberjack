<?php

declare(strict_types=1);

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Actions\SetSuspendedAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Alerts\Actions\SetAlertRuleAddOnsAction;
use App\Domains\Alerts\Actions\UpdateAlertRuleAction;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Health\Actions\DeleteHealthCheckAction;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Actions\UpdateLabelAction;
use App\Domains\Issues\Enums\LabelColor;
use App\Domains\Issues\Models\Label;
use App\Domains\Live\Observers\LiveModelObserver;
use App\Domains\Mcp\Actions\CreateMcpTokenAction;
use App\Domains\Mcp\Enums\McpScope;
use App\Domains\Projects\Actions\UpdateProjectAction;
use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\SaveRetentionPolicyAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Teams\Actions\ChangeMemberRoleAction;
use App\Domains\Teams\Actions\InviteMemberAction;
use App\Domains\Teams\Actions\RemoveMemberAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Domains\Teams\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Live\ChecksLiveBroadcasts;

uses(ChecksLiveBroadcasts::class);

covers(LiveModelObserver::class, UpdateAlertRuleAction::class, SetAlertRuleAddOnsAction::class);

beforeEach(fn () => Notification::fake());

describe('team pages', function () {
    beforeEach(function () {
        $this->team = Team::factory()->create();
        $this->owner = User::factory()->inTeam($this->team)->create();
        $this->member = User::factory()->inTeam($this->team, TeamRole::Member)->create();
        $this->channel = 'private-team.'.$this->team->uuid;
    });

    it('announces an invitation, and its removal from the page that deletes it inline', function () {
        $this->listenForBroadcasts();
        app(InviteMemberAction::class)->execute($this->team, 'new@example.com', TeamRole::Member, $this->owner);

        expect($this->broadcasts())->toBe([$this->channel.' invitations.changed']);

        $this->listenForBroadcasts();
        $this->actingAs($this->owner)->delete(route('invitations.destroy', TeamInvitation::query()->firstOrFail()))->assertRedirect();

        expect($this->broadcasts())->toBe([$this->channel.' invitations.changed']);
    });

    it('announces a member\'s new role and their removal to the team and the administrators', function (string $change) {
        $this->listenForBroadcasts();

        match ($change) {
            'role' => app(ChangeMemberRoleAction::class)->execute($this->team, $this->member, TeamRole::Admin),
            'removal' => app(RemoveMemberAction::class)->execute($this->team, $this->member),
        };

        expect($this->broadcasts())->toBe(['private-admin teams.changed', 'private-admin users.changed', $this->channel.' members.changed']);
    })->with(['role', 'removal']);

    it('announces a new MCP token', function () {
        $this->listenForBroadcasts();
        app(CreateMcpTokenAction::class)->execute($this->team, $this->owner, 'CI', McpScope::cases()[0], [], null);

        expect($this->broadcasts())->toBe([$this->channel.' tokens.changed']);
    });

    it('announces a changed alert rule, its channels included, which have no model events', function () {
        $rule = AlertRule::query()->where('team_id', $this->team->id)->firstOrFail();
        $channel = NotificationChannel::factory()->for($this->team)->create();
        $this->listenForBroadcasts();

        app(UpdateAlertRuleAction::class)->execute($rule, $rule->enabled, [$channel->id], null);

        expect($this->broadcasts())->toBe([$this->channel.' alerts.changed']);
    });

    it('announces the channels a project adds to a team rule', function () {
        $project = Project::factory()->for($this->team)->create();
        $rule = AlertRule::query()->where('team_id', $this->team->id)->firstOrFail();
        $channel = NotificationChannel::factory()->for($this->team)->create();
        $this->listenForBroadcasts();

        app(SetAlertRuleAddOnsAction::class)->execute($project, $rule, [$channel->id]);

        expect($this->broadcasts())->toBe([$this->channel.' alerts.changed']);
    });

    it('announces the team\'s retention, and the application\'s to every team', function () {
        $other = Team::factory()->create();
        $this->listenForBroadcasts();
        app(SaveRetentionPolicyAction::class)->execute(['team_id' => $this->team->id], RetentionCategory::Occurrences, 30);

        expect($this->broadcasts())->toBe([$this->channel.' retention.changed']);

        $this->listenForBroadcasts();
        app(SaveRetentionPolicyAction::class)->execute([], RetentionCategory::Occurrences, 60);

        expect($this->broadcasts())->toBe(collect(['private-admin retention.changed', 'private-team.'.$other->uuid.' retention.changed', $this->channel.' retention.changed'])->sort()->values()->all());
    });
});

describe('project settings', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create();
        $this->channel = 'private-project.'.$this->project->uuid;
    });

    it('announces a renamed label to the settings and the issue pages', function () {
        $label = Label::factory()->for($this->project)->create();
        $this->listenForBroadcasts();

        app(UpdateLabelAction::class)->execute($label, 'renamed', LabelColor::Red);

        expect($this->broadcasts())->toBe([$this->channel.' issues.changed', $this->channel.' settings.changed']);
    });

    it('announces a renamed project to its settings and to the team\'s project lists', function () {
        $this->listenForBroadcasts();

        app(UpdateProjectAction::class)->execute($this->project, 'Renamed', $this->project->slug, []);

        expect($this->broadcasts())->toBe([$this->channel.' settings.changed', 'private-team.'.$this->project->team->uuid.' projects.changed']);
    });

    it('announces the project\'s retention to its settings', function () {
        $this->listenForBroadcasts();
        app(SaveRetentionPolicyAction::class)->execute(['project_id' => $this->project->id], RetentionCategory::Occurrences, 7);

        expect($this->broadcasts())->toBe([$this->channel.' settings.changed']);
    });

    it('announces a removed health check', function () {
        $check = HealthCheck::factory()->for($this->project)->create();
        $this->listenForBroadcasts();

        app(DeleteHealthCheckAction::class)->execute($check);

        expect($this->broadcasts())->toBe([$this->channel.' health.changed']);
    });
});

describe('administration pages', function () {
    it('announces a suspended user and the audit entry it leaves', function () {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $this->listenForBroadcasts();

        app(SetSuspendedAction::class)->execute($admin, $user, true);
        app(RecordAdminAuditAction::class)->execute($admin, AdminAction::TeamCreated, 'x');

        expect($this->broadcasts())->toContain('private-admin users.changed', 'private-admin audit.changed');
    });
});

describe('what is not announced', function () {
    it('stays quiet for a save that changed nothing', function () {
        $label = Label::factory()->create();
        $this->listenForBroadcasts();

        $label->save();
        app(UpdateLabelAction::class)->execute($label, $label->name, $label->color);

        expect($this->broadcasts())->toBe([]);
    });

    it('stays quiet for bookkeeping that is broadcast elsewhere or not shown', function () {
        $check = HealthCheck::factory()->create();
        $user = User::factory()->create();
        $this->listenForBroadcasts();

        $check->forceFill(['next_run_at' => now()->addMinute(), 'last_result_at' => now()])->save();
        $user->forceFill(['remember_token' => 'abc'])->save();

        expect($this->broadcasts())->toBe([]);
    });

    it('stays quiet when an alert rule is saved as it was', function () {
        $rule = AlertRule::query()->where('team_id', Team::factory()->create()->id)->firstOrFail();
        $this->listenForBroadcasts();

        app(UpdateAlertRuleAction::class)->execute($rule, $rule->enabled, [], null);

        expect($this->broadcasts())->toBe([]);
    });
});
