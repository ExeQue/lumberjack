<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\SaveScreenTemplateAction;
use App\Domains\Screens\Http\Requests\SaveScreenTemplateRequest;
use App\Domains\Screens\Models\SavedScreenTemplate;
use App\Domains\Screens\Models\Screen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** The team's own screen templates. See docs/screens.md. */
class SavedScreenTemplateController
{
    public function store(SaveScreenTemplateRequest $request, Screen $screen, SaveScreenTemplateAction $action): RedirectResponse
    {
        $template = $action->execute($screen, $request->string('name')->trim()->toString(), $request->description(), $request->user());

        return back()->with('status', __('screens.templates.saved', ['name' => $template->name]));
    }

    public function update(SaveScreenTemplateRequest $request, SavedScreenTemplate $template): RedirectResponse
    {
        $template->update(['name' => $request->string('name')->trim()->toString(), 'description' => $request->description()]);

        return back()->with('status', __('screens.templates.updated'));
    }

    public function destroy(SavedScreenTemplate $template): RedirectResponse
    {
        Gate::authorize('delete', $template);
        $template->delete();

        return back()->with('status', __('screens.templates.deleted', ['name' => $template->name]));
    }
}
