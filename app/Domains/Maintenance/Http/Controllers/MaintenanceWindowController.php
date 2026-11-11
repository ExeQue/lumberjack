<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Controllers;

use App\Domains\Maintenance\Actions\EndMaintenanceWindowAction;
use App\Domains\Maintenance\Actions\SaveMaintenanceWindowAction;
use App\Domains\Maintenance\Http\Requests\SaveMaintenanceWindowRequest;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Planned windows from the Health page: plan, change and end one. The app's own come in through ingest.
 */
class MaintenanceWindowController
{
    public function store(SaveMaintenanceWindowRequest $request, Project $project, SaveMaintenanceWindowAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $action->execute($project, null, $request->string('environment')->toString(), $request->startsAt(), $request->endsAt(), $request->filled('message') ? $request->string('message')->toString() : null);

        return back()->with('status', __('maintenance.saved'));
    }

    public function update(SaveMaintenanceWindowRequest $request, Project $project, MaintenanceWindow $maintenanceWindow, SaveMaintenanceWindowAction $action): RedirectResponse
    {
        Gate::authorize('update', $maintenanceWindow);

        $action->execute($project, $maintenanceWindow, $request->string('environment')->toString(), $request->startsAt(), $request->endsAt(), $request->filled('message') ? $request->string('message')->toString() : null);

        return back()->with('status', __('maintenance.saved'));
    }

    public function end(Project $project, MaintenanceWindow $maintenanceWindow, EndMaintenanceWindowAction $action): RedirectResponse
    {
        Gate::authorize('update', $maintenanceWindow);

        $action->execute($maintenanceWindow);

        return back()->with('status', __('maintenance.ended'));
    }
}
