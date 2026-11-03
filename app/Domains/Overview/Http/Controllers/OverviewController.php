<?php

declare(strict_types=1);

namespace App\Domains\Overview\Http\Controllers;

use App\Domains\Overview\Queries\ProjectOverviewQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, ProjectOverviewQuery $overview, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/overview', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => $this->filters($request),
            // Deferred: the page shell paints at once on a cold statistics cache.
            'overview' => Inertia::defer(fn (): array => $overview->get($project, $this->period($request), $this->environment($request))),
        ]);
    }
}
