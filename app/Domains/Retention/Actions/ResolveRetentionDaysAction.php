<?php

declare(strict_types=1);

namespace App\Domains\Retention\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use Illuminate\Database\Eloquent\Collection;

/**
 * The most specific setting wins: job class or log channel, then project, then team, then default.
 */
class ResolveRetentionDaysAction
{
    /**
     * @param  Collection<int, RetentionPolicy>|null  $policies  preloaded policies of the project and its team
     */
    public function execute(Project $project, RetentionCategory $category, ?string $jobClass = null, ?int $logChannelId = null, ?Collection $policies = null): int
    {
        $policies ??= $this->policiesFor($project);

        $ofCategory = $policies->filter(fn (RetentionPolicy $p): bool => $p->category === $category);

        $specific = $ofCategory->first(fn (RetentionPolicy $p): bool => $p->project_id === $project->id
            && (($jobClass !== null && $p->job_class === $jobClass) || ($logChannelId !== null && $p->log_channel_id === $logChannelId)));

        $projectWide = $ofCategory->first(fn (RetentionPolicy $p): bool => $p->project_id === $project->id && $p->job_class === null && $p->log_channel_id === null);

        $teamWide = $ofCategory->first(fn (RetentionPolicy $p): bool => $p->team_id === $project->team_id && $p->project_id === null);

        return ($specific ?? $projectWide ?? $teamWide)->days ?? $category->defaultDays();
    }

    /**
     * @return Collection<int, RetentionPolicy>
     */
    public function policiesFor(Project $project): Collection
    {
        return RetentionPolicy::query()
            ->where('project_id', $project->id)
            ->orWhere(fn ($q) => $q->where('team_id', $project->team_id)->whereNull('project_id'))
            ->get();
    }
}
