<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Forgetting an agent whose server is gone, before it drops off by itself.
 */
class ProjectAgentController
{
    public function destroy(Project $project, ProjectAgent $agent): RedirectResponse
    {
        Gate::authorize('update', $project);

        $agent->delete();

        return back()->with('status', __('projects.agent_removed', ['host' => $agent->host]));
    }
}
