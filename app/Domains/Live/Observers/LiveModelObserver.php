<?php

declare(strict_types=1);

namespace App\Domains\Live\Observers;

use App\Domains\Administration\Models\AdminAuditEntry;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Models\QueueAlertThreshold;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Label;
use App\Domains\Live\Actions\BroadcastAdminChangeAction;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Actions\BroadcastTeamChangeAction;
use App\Domains\Live\Enums\AdminChangeKind;
use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Live\Enums\TeamChangeKind;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Repositories\Models\ProjectRepository;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Teams\Models\Membership;
use App\Domains\Teams\Models\Team;
use App\Domains\Teams\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a change to what a settings, team or administration page shows is
 * broadcast, whoever made it: an Action, a controller deleting a row, an MCP tool or
 * a console command. Telemetry written by ingest is broadcast by ingest itself.
 */
class LiveModelObserver
{
    /** @var list<class-string<Model>> */
    public const array MODELS = [
        AdminAuditEntry::class,
        AlertRule::class,
        HealthCheck::class,
        Label::class,
        LogChannel::class,
        McpToken::class,
        Membership::class,
        NotificationChannel::class,
        Project::class,
        ProjectAgent::class,
        ProjectRepository::class,
        QueueAlertThreshold::class,
        RetentionPolicy::class,
        ScheduledTask::class,
        Team::class,
        TeamInvitation::class,
        User::class,
    ];

    /**
     * Columns whose change alone is no reason to reload anything: bookkeeping, or state
     * that the code writing it broadcasts itself (a health result, a heartbeat).
     *
     * @var list<string>
     */
    private const array QUIET = [
        'updated_at', 'remember_token', 'password', 'current_team_id', 'notification_preferences',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'email_verified_at',
        'next_run_at', 'last_result_at', 'last_ping_at', 'status', 'status_changed_at', 'alerted_status', 'alerted_at',
        'last_seen_at', 'last_issue_number',
    ];

    public function __construct(
        private readonly BroadcastProjectChangeAction $project,
        private readonly BroadcastTeamChangeAction $team,
        private readonly BroadcastAdminChangeAction $admin,
    ) {
    }

    public function created(Model $model): void
    {
        $this->changed($model, created: true);
    }

    public function updated(Model $model): void
    {
        if (array_diff(array_keys($model->getChanges()), self::QUIET) !== []) {
            $this->changed($model);
        }
    }

    public function deleted(Model $model): void
    {
        $this->changed($model, deleted: true);
    }

    /** A user's memberships go with them by cascade, without events of their own. */
    public function deleting(Model $model): void
    {
        if ($model instanceof User) {
            $model->teams()->pluck('teams.id')->each(fn (int $id) => $this->team->execute($id, TeamChangeKind::Members));
        }
    }

    private function changed(Model $model, bool $created = false, bool $deleted = false): void
    {
        match (true) {
            $model instanceof AdminAuditEntry => $this->admin->execute(AdminChangeKind::Audit),
            $model instanceof AlertRule => $this->alerts($model->team_id ?? $model->project?->team_id),
            $model instanceof NotificationChannel => $this->alerts($model->team_id),
            $model instanceof HealthCheck => $this->project->execute($model->project_id, ChangeKind::Health),
            $model instanceof ScheduledTask => $deleted ? $this->project->execute($model->project_id, ChangeKind::Schedule) : null,
            $model instanceof Label => $this->projectKinds($model->project_id, ChangeKind::Settings, ChangeKind::Issues),
            $model instanceof LogChannel => $this->projectKinds($model->project_id, ChangeKind::Settings, ChangeKind::Logs),
            $model instanceof QueueAlertThreshold => $this->project->execute($model->project_id, ChangeKind::Settings),
            $model instanceof ProjectRepository => $this->project->execute($model->project_id, ChangeKind::Settings),
            // Heartbeats broadcast agents themselves; removing one is a settings action.
            $model instanceof ProjectAgent => $deleted ? $this->project->execute($model->project_id, ChangeKind::Agents) : null,
            $model instanceof McpToken => $this->team->execute($model->team_id, TeamChangeKind::Tokens),
            $model instanceof TeamInvitation => $this->team->execute($model->team_id, TeamChangeKind::Invitations),
            $model instanceof RetentionPolicy => $this->retention($model),
            $model instanceof Project => $this->projectChanged($model, $created || $deleted),
            $model instanceof Membership => $this->membershipChanged($model->team_id),
            $model instanceof Team => $this->adminKinds(AdminChangeKind::Teams, AdminChangeKind::Users),
            $model instanceof User => $this->userChanged($model, $created || $deleted),
            default => null,
        };
    }

    private function alerts(?int $teamId): void
    {
        if ($teamId !== null) {
            $this->team->execute($teamId, TeamChangeKind::Alerts);
        }
    }

    private function projectKinds(int $projectId, ChangeKind ...$kinds): void
    {
        foreach ($kinds as $kind) {
            $this->project->execute($projectId, $kind);
        }
    }

    private function adminKinds(AdminChangeKind ...$kinds): void
    {
        foreach ($kinds as $kind) {
            $this->admin->execute($kind);
        }
    }

    /** A setting at one level shows on every level below it. */
    private function retention(RetentionPolicy $policy): void
    {
        if ($policy->project_id !== null) {
            $this->project->execute($policy->project_id, ChangeKind::Settings);
        } elseif ($policy->team_id !== null) {
            $this->team->execute($policy->team_id, TeamChangeKind::Retention);
        } else {
            $this->admin->execute(AdminChangeKind::Retention);
            $this->team->everyTeam(TeamChangeKind::Retention);
        }
    }

    private function projectChanged(Project $project, bool $createdOrDeleted): void
    {
        if ($createdOrDeleted || $project->wasChanged(['name', 'slug'])) {
            $this->team->execute($project->team_id, TeamChangeKind::Projects);
        }

        if ($createdOrDeleted) {
            $this->admin->execute(AdminChangeKind::Teams);

            return;
        }

        $this->project->execute($project->id, ChangeKind::Settings);
    }

    private function membershipChanged(int $teamId): void
    {
        $this->team->execute($teamId, TeamChangeKind::Members);
        $this->adminKinds(AdminChangeKind::Teams, AdminChangeKind::Users);
    }

    private function userChanged(User $user, bool $createdOrDeleted): void
    {
        $this->admin->execute(AdminChangeKind::Users);

        // Who owns a team, and who is in it, shows by name and address.
        if (! $createdOrDeleted && $user->wasChanged(['name', 'email'])) {
            $this->admin->execute(AdminChangeKind::Teams);
            $user->teams()->pluck('teams.id')->each(fn (int $id) => $this->team->execute($id, TeamChangeKind::Members));
        }
    }
}
