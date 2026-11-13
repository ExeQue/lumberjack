<?php

declare(strict_types=1);

namespace App\Domains\Activity\Http\Controllers;

use App\Domains\Activity\Actions\SetActivityLabelAction;
use App\Domains\Activity\Http\Requests\SetActivityLabelRequest;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;

/**
 * The names a team gives its activity groups and activities.
 */
class ActivityLabelController
{
    public function update(SetActivityLabelRequest $request, Project $project, SetActivityLabelAction $action): RedirectResponse
    {
        $name = $request->string('name')->toString();
        $action->execute($project, $request->string('group')->toString(), $name !== '' ? $name : null, $request->string('label')->toString());

        return back();
    }
}
