<?php

declare(strict_types=1);

namespace App\Domains\Performance\Http\Controllers;

use App\Domains\Performance\Queries\TraceQuery;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One trace on one time axis: the request or job, its queries, spans, log lines and
 * exceptions, in the order they happened.
 */
class TraceController
{
    public function __invoke(Request $request, Project $project, string $trace, TraceQuery $query, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        $data = $query->get($project->id, $trace);

        abort_if($data['requests'] === [] && $data['jobs'] === [] && $data['timeline'] === [], 404);

        return Inertia::render('traces/show', [
            'project' => $presenter->header($project, $request->user()),
            'trace' => ['id' => $trace, ...$data],
        ]);
    }
}
