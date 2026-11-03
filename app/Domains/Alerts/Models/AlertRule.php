<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Alerts\Data\QueueBacklogSettings;
use App\Domains\Alerts\Eloquent\Builders\AlertRuleBuilder;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A team's rule, which its projects use, or a project's own that overrides the team's
 * of that type. Never both; resolve which counts with whereEffectiveFor() and where it
 * sends with channelsFor().
 *
 * @property int $id
 * @property int|null $team_id
 * @property int|null $project_id
 * @property AlertRuleType $type
 * @property bool $enabled
 * @property list<string>|null $environments
 * @property array<string, mixed>|null $settings
 */
#[Fillable(['team_id', 'project_id', 'type', 'enabled', 'environments', 'settings'])]
#[UseFactory(AlertRuleFactory::class)]
#[UseEloquentBuilder(AlertRuleBuilder::class)]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    /**
     * At most one message per issue per rule within this window.
     */
    public const int THROTTLE_MINUTES = 30;

    protected function casts(): array
    {
        return [
            'type' => AlertRuleType::class,
            'enabled' => 'boolean',
            'environments' => 'array',
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isTeamRule(): bool
    {
        return $this->team_id !== null;
    }

    /** @return BelongsToMany<NotificationChannel, $this> */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class);
    }

    /**
     * Channels the project adds to this team rule without customizing it.
     *
     * @return BelongsToMany<NotificationChannel, $this>
     */
    public function addOnsFor(Project $project): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class, 'project_alert_rule_channel')->withPivotValue('project_id', $project->id);
    }

    /**
     * Where this rule sends for the project: a project's own rule to its channels, a team
     * rule to the team's channels and the ones the project added to it.
     *
     * @return Collection<int, NotificationChannel>
     */
    public function channelsFor(Project $project): Collection
    {
        if ($this->project_id !== null) {
            return $this->channels;
        }

        return $this->channels->merge($this->addOnsFor($project)->get())->unique('id')->values();
    }

    public function queueBacklogSettings(): QueueBacklogSettings
    {
        return QueueBacklogSettings::fromArray($this->settings);
    }

    public function appliesToEnvironment(?string $environment): bool
    {
        return $this->environments === null || $this->environments === [] || in_array($environment, $this->environments, true);
    }
}
