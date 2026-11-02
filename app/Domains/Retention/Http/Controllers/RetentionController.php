<?php

declare(strict_types=1);

namespace App\Domains\Retention\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\SaveRetentionPolicyAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Http\Requests\UpdateRetentionRequest;
use App\Domains\Retention\Models\RetentionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RetentionController
{
    public function project(UpdateRetentionRequest $request, Project $project, SaveRetentionPolicyAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        DB::transaction(function () use ($request, $project, $action): void {
            foreach (RetentionCategory::cases() as $category) {
                $days = $request->input('days.'.$category->value);
                $action->execute(['project_id' => $project->id], $category, $days !== null ? (int) $days : null);
            }

            if ($request->has('job_overrides')) {
                RetentionPolicy::query()->where('project_id', $project->id)->whereNotNull('job_class')->delete();

                foreach ((array) $request->input('job_overrides') as $override) {
                    $action->execute(['project_id' => $project->id, 'job_class' => $override['job_class']], RetentionCategory::Jobs, (int) $override['days']);
                }
            }

            if ($request->has('channels')) {
                $channelIds = $project->logChannels()->pluck('id')->all();

                foreach ((array) $request->input('channels') as $channelId => $days) {
                    if (in_array((int) $channelId, $channelIds, true)) {
                        $action->execute(['project_id' => $project->id, 'log_channel_id' => (int) $channelId], RetentionCategory::Logs, $days !== null ? (int) $days : null);
                    }
                }
            }
        });

        return back()->with('status', __('retention.saved'));
    }

    public function team(UpdateRetentionRequest $request, SaveRetentionPolicyAction $action): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('administer', $team);

        foreach (RetentionCategory::cases() as $category) {
            $days = $request->input('days.'.$category->value);
            $action->execute(['team_id' => $team->id], $category, $days !== null ? (int) $days : null);
        }

        return back()->with('status', __('retention.saved'));
    }
}
