<?php

declare(strict_types=1);

namespace App\Domains\Retention\Actions;

use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Shared\Support\ChunkedDeleter;

/**
 * Deletes raw rows past their retention. Issues themselves are kept; only
 * their occurrences age out.
 */
class PruneProjectTelemetryAction
{
    public function __construct(
        private readonly ResolveRetentionDaysAction $resolve,
        private readonly ChunkedDeleter $deleter,
    ) {
    }

    /**
     * @return array<string, int> rows deleted per table
     */
    public function execute(Project $project): array
    {
        $policies = $this->resolve->policiesFor($project);
        $days = fn (RetentionCategory $c, ?string $job = null, ?int $channel = null): int => $this->resolve->execute($project, $c, $job, $channel, $policies);
        $before = fn (int $d): string => now()->subDays($d)->format('Y-m-d H:i:s');
        $deleted = [];

        $errorsCutoff = $before($days(RetentionCategory::Errors));
        $deleted['occurrences'] = $this->deleter->delete('occurrences', fn ($q) => $q->where('project_id', $project->id)->where('occurred_at', '<', $errorsCutoff));

        $performanceCutoff = $before($days(RetentionCategory::Performance));
        foreach (['request_runs', 'query_runs', 'span_runs', 'web_vitals'] as $table) {
            $deleted[$table] = $this->deleter->delete($table, fn ($q) => $q->where('project_id', $project->id)->where('occurred_at', '<', $performanceCutoff));
        }

        // Failed runs live as long as the errors they belong to.
        $deleted['job_runs'] = $this->deleter->delete('job_runs', fn ($q) => $q->where('project_id', $project->id)
            ->where('status', JobRunStatus::Failed->value)->where('created_at', '<', $errorsCutoff));

        $overrides = $policies->filter(fn (RetentionPolicy $p): bool => $p->project_id === $project->id && $p->job_class !== null);
        foreach ($overrides as $override) {
            $cutoff = $before($override->days);
            $deleted['job_runs'] += $this->deleter->delete('job_runs', fn ($q) => $q->where('project_id', $project->id)
                ->where('name', $override->job_class)->where('status', '!=', JobRunStatus::Failed->value)->where('created_at', '<', $cutoff));
        }

        $jobsCutoff = $before($days(RetentionCategory::Jobs));
        $overridden = $overrides->pluck('job_class')->all();
        $deleted['job_runs'] += $this->deleter->delete('job_runs', fn ($q) => $q->where('project_id', $project->id)
            ->where('status', '!=', JobRunStatus::Failed->value)->whereNotIn('name', $overridden)->where('created_at', '<', $jobsCutoff));

        // Scheduled runs follow the jobs retention; failed and missed ones the errors'.
        $deleted['scheduled_task_runs'] = $this->deleter->delete('scheduled_task_runs', fn ($q) => $q->where('project_id', $project->id)
            ->whereIn('status', ['failed', 'missed'])->where('created_at', '<', $errorsCutoff));
        $deleted['scheduled_task_runs'] += $this->deleter->delete('scheduled_task_runs', fn ($q) => $q->where('project_id', $project->id)
            ->whereNotIn('status', ['failed', 'missed'])->where('created_at', '<', $jobsCutoff));

        // Command runs follow the jobs retention; failed ones the errors'.
        $deleted['command_runs'] = $this->deleter->delete('command_runs', fn ($q) => $q->where('project_id', $project->id)
            ->where('status', 'failed')->where('created_at', '<', $errorsCutoff));
        $deleted['command_runs'] += $this->deleter->delete('command_runs', fn ($q) => $q->where('project_id', $project->id)
            ->where('status', '!=', 'failed')->where('created_at', '<', $jobsCutoff));

        $deleted['log_entries'] = 0;
        foreach ($project->logChannels()->pluck('id') as $channelId) {
            $cutoff = $before($days(RetentionCategory::Logs, channel: $channelId));
            $deleted['log_entries'] += $this->deleter->delete('log_entries', fn ($q) => $q->where('project_id', $project->id)
                ->where('log_channel_id', $channelId)->where('occurred_at', '<', $cutoff));
        }

        return $deleted;
    }
}
