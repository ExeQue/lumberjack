<?php

declare(strict_types=1);

namespace App\Domains\Retention\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use Illuminate\Support\Facades\DB;

/**
 * A project's retention table as the settings page saves it: days per data type (a type
 * without days and not kept forever inherits again), and optionally the per-job and
 * per-channel overrides. Caps are checked when UpdateRetentionRequest is validated.
 */
class SaveProjectRetentionAction
{
    public function __construct(
        private readonly SaveRetentionPolicyAction $save,
    ) {
    }

    /**
     * @param  array<string, int|string|null>  $days  by category value
     * @param  list<string>  $forever  category values kept forever
     * @param  list<array{job_class: string, days: int|string}>|null  $jobOverrides  null leaves them as they are; a list replaces them all
     * @param  array<int|string, int|string|null>|null  $channels  days by log channel id, null days inherits; channels of other projects are skipped
     */
    public function execute(Project $project, array $days, array $forever, ?array $jobOverrides = null, ?array $channels = null): void
    {
        DB::transaction(function () use ($project, $days, $forever, $jobOverrides, $channels): void {
            $scope = ['project_id' => $project->id];

            foreach (RetentionCategory::forTeams() as $category) {
                $value = $days[$category->value] ?? null;
                $this->save->execute($scope, $category, $value !== null ? (int) $value : null, in_array($category->value, $forever, true));
            }

            if ($jobOverrides !== null) {
                RetentionPolicy::query()->whereProject($project->id)->whereNotNull('job_class')->delete();

                foreach ($jobOverrides as $override) {
                    $this->save->execute([...$scope, 'job_class' => $override['job_class']], RetentionCategory::JobRuns, (int) $override['days']);
                }
            }

            if ($channels !== null) {
                $channelIds = $project->logChannels()->pluck('id')->all();

                foreach ($channels as $channelId => $channelDays) {
                    if (in_array((int) $channelId, $channelIds, true)) {
                        $this->save->execute([...$scope, 'log_channel_id' => (int) $channelId], RetentionCategory::Logs, $channelDays !== null ? (int) $channelDays : null);
                    }
                }
            }
        });
    }
}
