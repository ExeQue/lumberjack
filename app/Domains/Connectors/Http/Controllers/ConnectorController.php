<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Http\Controllers;

use App\Domains\Connectors\Presenters\ConnectorPresenter;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The outside services a project can connect to, and what it has connected of each. Every
 * member sees it; owners and admins connect from it.
 */
class ConnectorController
{
    public function __invoke(Request $request, Project $project, ProjectPresenter $projects, ConnectorPresenter $connectors): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('connectors/index', [
            'project' => $projects->header($project, $request->user()),
            'connectors' => $connectors->catalog($project),
            'tags' => $connectors->tags(),
        ]);
    }
}
