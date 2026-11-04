<?php

declare(strict_types=1);

namespace App\Domains\Schedule\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Schedule\Actions\DeleteScheduledTaskAction;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleController
{
    use ReadsDashboardFilters;

    public const int RECENT_RUNS = 20;

    public function index(Request $request, Project $project, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        $environment = $this->environment($request);
        $selected = $request->integer('task') ?: null;

        $tasks = $project->scheduledTasks()
            ->when($environment !== null)->where('environment', $environment)
            ->orderBy('name')
            // The latest runs of every task in one query.
            ->with(['runs' => fn (HasMany $runs) => $runs->select(['id', 'scheduled_task_id', 'status', 'started_at'])->latest('started_at')->limit(self::RECENT_RUNS)])
            ->get();

        $selectedTask = $selected !== null ? $tasks->firstWhere('id', $selected) : null;

        return Inertia::render('schedule/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->filters($request), 'task' => $selectedTask?->id],
            'tasks' => $tasks->map(fn (ScheduledTask $task): array => [
                ...$this->task($task),
                'recent' => $task->runs->pluck('status')->map->value->reverse()->values()->all(),
            ])->all(),
            'runs' => $selectedTask === null ? [] : $selectedTask->runs()->with('occurrence.issue')->latest('started_at')->limit(50)->get()
                ->map(fn (ScheduledTaskRun $run): array => [
                    'id' => $run->id,
                    'status' => $run->status->value,
                    'started_at' => $run->started_at?->toIso8601String(),
                    'duration_us' => $run->duration_us,
                    'exit_code' => $run->exit_code,
                    'host' => $run->host,
                    'trace_id' => $run->trace_id,
                    'issue' => $run->occurrence?->issue?->uuid,
                ])->all(),
        ]);
    }

    public function destroy(Project $project, ScheduledTask $scheduledTask, DeleteScheduledTaskAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $action->execute($scheduledTask);

        return redirect()->route('schedule.index', $project)->with('status', __('schedule.task_deleted', ['task' => $scheduledTask->name]));
    }

    /**
     * @return array<string, mixed>
     */
    private function task(ScheduledTask $task): array
    {
        $late = $task->next_run_at !== null && $task->next_run_at->lt(now()->subSeconds(ScheduledTask::LATE_AFTER_SECONDS)) && ($task->last_run_at === null || $task->last_run_at->lt($task->next_run_at));

        return [
            'id' => $task->id,
            'name' => $task->name,
            'environment' => $task->environment !== '' ? $task->environment : null,
            'expression' => $task->expression,
            'timezone' => $task->timezone,
            'last_status' => $task->last_status?->value,
            'last_run_at' => $task->last_run_at?->toIso8601String(),
            'last_duration_us' => $task->last_duration_us,
            'next_run_at' => $task->next_run_at?->toIso8601String(),
            'late' => $late,
        ];
    }
}
