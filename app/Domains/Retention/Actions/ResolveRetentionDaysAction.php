<?php

declare(strict_types=1);

namespace App\Domains\Retention\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Data\ResolvedRetention;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Enums\RetentionLevel;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one place retention is decided. The most specific stored value wins (job class or log
 * channel, project, team, application, built-in default), but never above what a stored value
 * of a level above allows: the application caps teams, the team caps its projects and their
 * job classes and channels. A capped value stays stored, so raising the cap restores it.
 */
class ResolveRetentionDaysAction
{
    /**
     * @param  Team|Project|null  $scope  null for the application level
     * @param  Collection<int, RetentionPolicy>|null  $policies  preloaded with policiesFor()
     */
    public function execute(RetentionCategory $category, Team|Project|null $scope = null, ?string $jobClass = null, ?int $logChannelId = null, ?Collection $policies = null): ResolvedRetention
    {
        $policies ??= $this->policiesFor($scope);
        $rows = $policies->filter(fn (RetentionPolicy $p): bool => $p->category === $category);
        $teamId = $scope instanceof Project ? $scope->team_id : $scope?->id;
        $projectId = $scope instanceof Project ? $scope->id : null;

        $levels = [[RetentionLevel::Application, $rows->first(fn (RetentionPolicy $p): bool => $p->team_id === null && $p->project_id === null)]];

        if ($teamId !== null && ! $category->applicationOnly()) {
            $levels[] = [RetentionLevel::Team, $rows->first(fn (RetentionPolicy $p): bool => $p->team_id === $teamId && $p->project_id === null)];
        }

        if ($projectId !== null && ! $category->applicationOnly()) {
            $levels[] = [RetentionLevel::Project, $rows->first(fn (RetentionPolicy $p): bool => $p->project_id === $projectId && $p->job_class === null && $p->log_channel_id === null)];

            if ($jobClass !== null || $logChannelId !== null) {
                $levels[] = [RetentionLevel::Override, $rows->first(fn (RetentionPolicy $p): bool => $p->project_id === $projectId
                    && (($jobClass !== null && $p->job_class === $jobClass) || ($logChannelId !== null && $p->log_channel_id === $logChannelId)))];
            }
        }

        $days = $category->defaultDays();
        $source = RetentionLevel::Default;
        $capped = false;
        $capDays = null;
        $capLevel = null;

        foreach ($levels as [$level, $row]) {
            if ($row === null) {
                continue;
            }

            // Forever is above any number of days.
            $capped = $capDays !== null && ($row->days === null || $row->days > $capDays);
            $days = $capped ? $capDays : $row->days;
            $source = $level;

            // A project's own value does not cap its job classes and channels.
            if (in_array($level, [RetentionLevel::Application, RetentionLevel::Team], true) && $days !== null && ! $this->isOwnLevel($level, $scope)) {
                $capDays = $days;
                $capLevel = $level;
            }
        }

        return new ResolvedRetention($days, $source, $capped, $capDays, $capLevel);
    }

    /**
     * The application's rows, plus the team's and the project's when given.
     *
     * @return Collection<int, RetentionPolicy>
     */
    public function policiesFor(Team|Project|null $scope = null): Collection
    {
        $teamId = $scope instanceof Project ? $scope->team_id : $scope?->id;

        return RetentionPolicy::query()
            ->whereApplication()
            ->when($teamId !== null)->orWhereTeamDefault((int) $teamId)
            ->when($scope instanceof Project)->orWhereProject((int) $scope?->id)
            ->get();
    }

    /**
     * The scope's own level caps nothing in its own answer: its cap is what the levels above allow.
     */
    private function isOwnLevel(RetentionLevel $level, Team|Project|null $scope): bool
    {
        return match ($level) {
            RetentionLevel::Application => $scope === null,
            RetentionLevel::Team => $scope instanceof Team,
            default => false,
        };
    }
}
