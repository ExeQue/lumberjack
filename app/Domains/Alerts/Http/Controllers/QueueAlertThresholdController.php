<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Http\Controllers;

use App\Domains\Alerts\Actions\SetQueueAlertThresholdAction;
use App\Domains\Alerts\Http\Requests\UpdateQueueAlertThresholdRequest;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class QueueAlertThresholdController
{
    public function update(UpdateQueueAlertThresholdRequest $request, Project $project, SetQueueAlertThresholdAction $action): RedirectResponse
    {
        $own = $action->execute(
            $project,
            $request->string('connection')->toString(),
            $request->string('queue')->toString(),
            $request->integerOrNull('size'),
            $request->integerOrNull('oldest_minutes'),
            $request->integerOrNull('sustained_minutes'),
            $request->boolean('muted'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __($own === null ? 'alerts.queue_threshold_reset' : 'alerts.queue_threshold_saved')]);

        return back();
    }
}
