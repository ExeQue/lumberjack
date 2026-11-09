<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Eloquent\Builders;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @extends Builder<AlertRule>
 */
class AlertRuleBuilder extends Builder
{
    /** The project's own rules: its overrides of the team's. */
    public function whereForProject(Project $project): self
    {
        return $this->where('alert_rules.project_id', $project->id);
    }

    public function orWhereForProject(Project $project): self
    {
        return $this->orWhere('alert_rules.project_id', $project->id);
    }

    /** The team's rules, which its projects use unless they have their own. */
    public function whereForTeam(Team $team): self
    {
        return $this->where('alert_rules.team_id', $team->id);
    }

    public function orWhereForTeam(Team $team): self
    {
        return $this->orWhere('alert_rules.team_id', $team->id);
    }

    /**
     * The rules that count for the project: its own, and the team's for every type it
     * has none of. One per type; whether a rule is on is up to the caller.
     */
    public function whereEffectiveFor(Project $project): self
    {
        return $this->where(fn (self $q) => $q->whereForProject($project)->orWhere(fn (self $q) => $q
            ->where('alert_rules.team_id', $project->team_id)
            ->whereNotExists(fn (QueryBuilder $own) => $own->from('alert_rules', 'own')->whereColumn('own.type', 'alert_rules.type')->whereColumn('own.topic', 'alert_rules.topic')->where('own.project_id', $project->id))));
    }

    public function orWhereEffectiveFor(Project $project): self
    {
        return $this->orWhere(fn (self $q) => $q->whereEffectiveFor($project));
    }

    public function whereType(AlertRuleType $type): self
    {
        return $this->where('alert_rules.type', $type->value);
    }

    public function orWhereType(AlertRuleType $type): self
    {
        return $this->orWhere('alert_rules.type', $type->value);
    }

    public function whereEnabled(): self
    {
        return $this->where('alert_rules.enabled', true);
    }

    public function orWhereEnabled(): self
    {
        return $this->orWhere('alert_rules.enabled', true);
    }
}
