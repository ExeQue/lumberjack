<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Controllers;

use App\Domains\Ai\Actions\DeleteAiBudgetAction;
use App\Domains\Ai\Actions\SaveAiBudgetAction;
use App\Domains\Ai\Http\Requests\SaveAiBudgetRequest;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Presenters\AiBudgetPresenter;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's AI budgets: how much a day or month the project, a key, a key owner or an
 * actor may use, and how far each is.
 */
class AiBudgetController
{
    public function index(Request $request, Project $project, ProjectPresenter $presenter, AiBudgetPresenter $budgets): Response
    {
        return Inertia::render('ai/budgets', [
            'project' => $presenter->header($project, $request->user()),
            'budgets' => Inertia::defer(fn (): array => $budgets->presentAll($project->aiBudgets()->with('project.team')->orderBy('name')->orderBy('id')->get())),
            'can_update' => (bool) $request->user()?->can('update', $project),
        ]);
    }

    public function store(SaveAiBudgetRequest $request, Project $project, SaveAiBudgetAction $action): RedirectResponse
    {
        $action->execute($project, null, $request->values());

        return back()->with('status', __('ui/ai.budgets.saved'));
    }

    public function update(SaveAiBudgetRequest $request, Project $project, AiBudget $aiBudget, SaveAiBudgetAction $action): RedirectResponse
    {
        $action->execute($project, $aiBudget, $request->values());

        return back()->with('status', __('ui/ai.budgets.saved'));
    }

    public function destroy(Project $project, AiBudget $aiBudget, DeleteAiBudgetAction $action): RedirectResponse
    {
        Gate::authorize('delete', $aiBudget);

        $action->execute($aiBudget);

        return back()->with('status', __('ui/ai.budgets.deleted'));
    }
}
