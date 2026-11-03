<?php

declare(strict_types=1);

namespace App\Domains\Retention\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\MeasureRetentionAction;
use App\Domains\Retention\Actions\SaveRetentionPolicyAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Http\Requests\UpdateRetentionRequest;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Retention\Presenters\RetentionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RetentionController
{
    /**
     * The application level: defaults and caps for every team. Admin middleware guards the route.
     */
    public function show(RetentionPresenter $presenter, MeasureRetentionAction $measure): Response
    {
        return Inertia::render('admin/retention', [
            'retention' => $presenter->rows(),
            'retention_stats' => Inertia::defer(fn (): array => $measure->execute(RetentionCategory::cases())),
        ]);
    }

    public function application(UpdateRetentionRequest $request, SaveRetentionPolicyAction $action): RedirectResponse
    {
        DB::transaction(fn () => $this->saveDays($request, [], $action));

        return back()->with('status', __('retention.saved'));
    }

    public function project(UpdateRetentionRequest $request, Project $project, SaveRetentionPolicyAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        DB::transaction(function () use ($request, $project, $action): void {
            $this->saveDays($request, ['project_id' => $project->id], $action);

            if ($request->has('job_overrides')) {
                RetentionPolicy::query()->whereProject($project->id)->whereNotNull('job_class')->delete();

                foreach ((array) $request->input('job_overrides') as $override) {
                    $action->execute(['project_id' => $project->id, 'job_class' => $override['job_class']], RetentionCategory::JobRuns, (int) $override['days']);
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

    /**
     * The team level: defaults and caps for the current team's projects. Members read it, admins change it.
     */
    public function teamShow(Request $request, RetentionPresenter $presenter, MeasureRetentionAction $measure): Response|RedirectResponse
    {
        $team = $request->user()->currentTeam;

        if ($team === null) {
            return redirect()->route('team.show');
        }

        return Inertia::render('team/retention', [
            'retention' => $presenter->rows($team),
            'retention_stats' => Inertia::defer(fn (): array => $measure->execute(RetentionCategory::forTeams(), $team)),
        ]);
    }

    public function team(UpdateRetentionRequest $request, SaveRetentionPolicyAction $action): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('administer', $team);

        DB::transaction(fn () => $this->saveDays($request, ['team_id' => $team->id], $action));

        return back()->with('status', __('retention.saved'));
    }

    /**
     * @param  array{team_id?: int, project_id?: int}  $scope
     */
    private function saveDays(UpdateRetentionRequest $request, array $scope, SaveRetentionPolicyAction $action): void
    {
        foreach ($request->categories() as $category) {
            $days = $request->input('days.'.$category->value);
            $action->execute($scope, $category, $days !== null ? (int) $days : null, $request->isForever($category));
        }
    }
}
