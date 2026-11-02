<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Controllers;

use App\Domains\Issues\Actions\DeleteLabelAction;
use App\Domains\Issues\Actions\UpdateLabelAction;
use App\Domains\Issues\Enums\LabelColor;
use App\Domains\Issues\Exceptions\IssueExceptionInterface;
use App\Domains\Issues\Http\Requests\UpdateLabelRequest;
use App\Domains\Issues\Models\Label;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class LabelController
{
    public function update(UpdateLabelRequest $request, Project $project, Label $label, UpdateLabelAction $action): RedirectResponse
    {
        try {
            $action->execute($label, $request->string('name')->toString(), LabelColor::from($request->string('color')->toString()));
        } catch (IssueExceptionInterface $e) {
            return back()->withErrors(['label' => $e->getMessage()]);
        }

        return back()->with('status', __('issues.label_saved'));
    }

    public function destroy(Project $project, Label $label, DeleteLabelAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $action->execute($label);

        return back()->with('status', __('issues.label_deleted', ['name' => $label->name]));
    }
}
