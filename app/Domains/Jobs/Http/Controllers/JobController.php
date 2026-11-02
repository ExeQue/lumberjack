<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Http\Controllers;

use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Jobs\Queries\JobStatisticsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class JobController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, JobStatisticsQuery $query, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        $sort = (string) $request->query('sort', 'errors');
        $queue = (string) $request->query('queue', '');
        $name = (string) $request->query('job', '');
        $period = $this->period($request);
        $environment = $this->environment($request);

        $runs = JobRun::query()
            ->where('project_id', $project->id)
            ->where('created_at', '>=', $period->since())
            ->when($environment !== null)->where('environment', $environment)
            ->when($name !== '')->where('name', $name)
            ->when($name === '')->whereStatus(JobRunStatus::Failed, JobRunStatus::Stuck, JobRunStatus::Released)
            ->with('occurrence.issue')
            ->latest('id')
            ->limit(25)
            ->get();

        return Inertia::render('jobs/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->filters($request), 'sort' => $sort, 'queue' => $queue, 'job' => $name],
            'queues' => JobRun::query()->where('project_id', $project->id)->where('created_at', '>=', now()->subDays(7))->distinct()->limit(20)->pluck('queue'),
            'stuck' => JobRun::query()->where('project_id', $project->id)->whereStatus(JobRunStatus::Stuck)->where('updated_at', '>=', now()->subDay())->count(),
            'stuck_names' => JobRun::query()->where('project_id', $project->id)->whereStatus(JobRunStatus::Stuck)->where('updated_at', '>=', now()->subDay())
                ->distinct()->limit(5)->pluck('name')->map(fn (string $name): string => class_basename($name))->values(),
            'jobs' => fn (): array => $query->get($project->id, $period, $environment, $sort, $queue),
            'runs' => $runs->map(fn (JobRun $r): array => [
                'id' => $r->id,
                'uuid' => $r->job_uuid,
                'name' => $r->name,
                'status' => $r->status->value,
                'attempt' => $r->attempt,
                'max_tries' => $r->max_tries,
                'queue' => $r->queue,
                'queued_at' => $r->queued_at?->toIso8601String(),
                'started_at' => $r->started_at?->toIso8601String(),
                'wait_us' => $r->queued_at !== null && $r->started_at !== null ? (int) ($r->queued_at->diffInMicroseconds($r->started_at)) : null,
                'duration_us' => $r->duration_us,
                'host' => $r->host,
                'parameters' => $r->parameters,
                'issue' => $r->occurrence?->issue?->uuid,
                'trace_id' => $r->trace_id,
            ])->all(),
        ]);
    }
}
