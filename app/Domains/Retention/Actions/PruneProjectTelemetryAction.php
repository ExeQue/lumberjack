<?php

declare(strict_types=1);

namespace App\Domains\Retention\Actions;

use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Shared\Support\ChunkedDeleter;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a project's rows past their retention, each data type by its own. Issues themselves
 * are kept; only their occurrences age out.
 */
class PruneProjectTelemetryAction
{
    public function __construct(
        private readonly ResolveRetentionDaysAction $resolve,
        private readonly ChunkedDeleter $deleter,
    ) {
    }

    /**
     * @return array<string, int> rows deleted per data type
     */
    public function execute(Project $project): array
    {
        $policies = $this->resolve->policiesFor($project);
        $days = fn (RetentionCategory $c, ?string $job = null, ?int $channel = null): ?int => $this->resolve->execute($c, $project, $job, $channel, $policies)->days;
        $deleted = array_fill_keys(array_map(fn (RetentionCategory $c): string => $c->value, RetentionCategory::forTeams()), 0);

        foreach (RetentionCategory::forTeams() as $category) {
            $deleted[$category->value] = match ($category) {
                RetentionCategory::JobRuns, RetentionCategory::ScheduledTaskRuns, RetentionCategory::CommandRuns, RetentionCategory::Logs => 0,
                default => $this->prune($project, $category, $days($category)),
            };
        }

        // Failed runs stay at least as long as the errors they belong to.
        $occurrences = $days(RetentionCategory::Occurrences);
        $failed = ['job_runs' => [JobRunStatus::Failed->value], 'scheduled_task_runs' => ['failed', 'missed'], 'command_runs' => ['failed']];

        foreach ([RetentionCategory::JobRuns, RetentionCategory::ScheduledTaskRuns, RetentionCategory::CommandRuns] as $category) {
            $statuses = $failed[$category->value];
            $deleted[$category->value] += $this->prune($project, $category, $this->longer($occurrences, $days($category)), fn (Builder $q) => $q->whereIn('status', $statuses));
        }

        $overridden = $policies->filter(fn (RetentionPolicy $p): bool => $p->project_id === $project->id && $p->job_class !== null)->pluck('job_class');

        foreach ($overridden as $jobClass) {
            $deleted['job_runs'] += $this->prune($project, RetentionCategory::JobRuns, $days(RetentionCategory::JobRuns, $jobClass), fn (Builder $q) => $q
                ->where('name', $jobClass)->whereNotIn('status', $failed['job_runs']));
        }

        $deleted['job_runs'] += $this->prune($project, RetentionCategory::JobRuns, $days(RetentionCategory::JobRuns), fn (Builder $q) => $q
            ->whereNotIn('name', $overridden->all())->whereNotIn('status', $failed['job_runs']));

        foreach ([RetentionCategory::ScheduledTaskRuns, RetentionCategory::CommandRuns] as $category) {
            $statuses = $failed[$category->value];
            $deleted[$category->value] += $this->prune($project, $category, $days($category), fn (Builder $q) => $q->whereNotIn('status', $statuses));
        }

        foreach ($project->logChannels()->pluck('id') as $channelId) {
            $deleted['logs'] += $this->prune($project, RetentionCategory::Logs, $days(RetentionCategory::Logs, channel: $channelId), fn (Builder $q) => $q->where('log_channel_id', $channelId));
        }

        return $deleted;
    }

    /**
     * Limits a query on the category's table to the rows of the given projects.
     *
     * @param  Project|int|list<int>  $projects
     */
    public static function scope(Builder $query, RetentionCategory $category, Project|int|array $projects): Builder
    {
        $ids = $projects instanceof Project ? [$projects->id] : (array) $projects;

        return match ($category) {
            RetentionCategory::HostSamples => $query->whereIn('project_agent_id', DB::table('project_agents')->whereIn('project_id', $ids)->select('id')),
            RetentionCategory::AlertSends => $query->whereIn('issue_id', DB::table('issues')->whereIn('project_id', $ids)->select('id')),
            default => $query->whereIn('project_id', $ids),
        };
    }

    /**
     * @param  Closure(Builder): mixed|null  $constrain
     */
    private function prune(Project $project, RetentionCategory $category, ?int $days, ?Closure $constrain = null): int
    {
        // Forever.
        if ($days === null) {
            return 0;
        }

        $cutoff = now()->subDays($days)->format('Y-m-d H:i:s');

        return $this->deleter->delete($category->table(), function (Builder $q) use ($project, $category, $cutoff, $constrain): void {
            self::scope($q, $category, $project)->where($category->timeColumn(), '<', $cutoff);

            if ($constrain !== null) {
                $constrain($q);
            }
        });
    }

    private function longer(?int $a, ?int $b): ?int
    {
        return $a === null || $b === null ? null : max($a, $b);
    }
}
