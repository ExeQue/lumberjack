<?php

declare(strict_types=1);

namespace App\Domains\Commands\Http\Controllers;

use App\Domains\Commands\Models\CommandRun;
use App\Domains\Commands\Queries\CommandStatisticsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CommandController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, CommandStatisticsQuery $query, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        $period = $this->period($request);
        $environment = $this->environment($request);
        $name = (string) $request->query('command', '');

        $runs = CommandRun::query()
            ->where('project_id', $project->id)
            ->where('created_at', '>=', $period->since())
            ->when($environment !== null)->where('environment', $environment)
            ->when($name !== '')->where('name', $name)
            ->with('occurrence.issue')
            ->latest('id')
            ->limit(50)
            ->get();

        return Inertia::render('commands/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->filters($request), 'command' => $name],
            'commands' => fn (): array => $query->get($project->id, $period, $environment),
            'runs' => $runs->map(fn (CommandRun $run): array => [
                'id' => $run->id,
                'name' => $run->name,
                'status' => $run->status->value,
                'arguments' => $run->arguments,
                'options' => $run->options,
                'exit_code' => $run->exit_code,
                'started_at' => $run->started_at?->toIso8601String(),
                'duration_us' => $run->duration_us,
                'host' => $run->host,
                'trace_id' => $run->trace_id,
                'issue' => $run->occurrence?->issue?->uuid,
            ])->all(),
        ]);
    }
}
