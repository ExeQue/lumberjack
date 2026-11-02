<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Repositories\Actions\LinkRepositoryAction;
use App\Domains\Repositories\Enums\RepositoryProvider;
use App\Domains\Repositories\Exceptions\RepositoryExceptionInterface;
use App\Domains\Repositories\Http\Requests\UpdateRepositoryRequest;
use App\Domains\Repositories\Providers\RepositoryDriverRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RepositoryController
{
    public function update(UpdateRepositoryRequest $request, Project $project, LinkRepositoryAction $action): RedirectResponse
    {
        $action->execute(
            $project,
            RepositoryProvider::from($request->string('provider')->toString()),
            $request->string('repository')->toString(),
            $request->filled('token') ? $request->string('token')->toString() : null,
            $request->input('token_expires_at'),
            $request->input('labels', []),
            $request->boolean('allow_public'),
        );

        return back()->with('status', __('repositories.saved'));
    }

    public function test(Request $request, Project $project, RepositoryDriverRegistry $drivers): RedirectResponse
    {
        Gate::authorize('update', $project);
        $repository = $project->repository;

        if ($repository === null) {
            return back()->withErrors(['repository' => __('repositories.not_linked')]);
        }

        try {
            $public = $drivers->for($repository->provider)->verify($repository);
        } catch (RepositoryExceptionInterface $e) {
            return back()->withErrors(['repository' => $e->getMessage()]);
        }

        return back()->with('status', __($public ? 'repositories.verified_public' : 'repositories.verified_private'));
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $project->repository()->delete();

        return back()->with('status', __('repositories.unlinked'));
    }
}
