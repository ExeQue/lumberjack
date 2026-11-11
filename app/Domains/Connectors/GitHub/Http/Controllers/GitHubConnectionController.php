<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Http\Controllers;

use App\Domains\Connectors\GitHub\Actions\ConnectGitHubAction;
use App\Domains\Connectors\GitHub\Exceptions\GitHubExceptionInterface;
use App\Domains\Connectors\GitHub\Http\Requests\ConnectGitHubRequest;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a project to a GitHub repository; the token never leaves the server again.
 */
class GitHubConnectionController
{
    public function store(ConnectGitHubRequest $request, Project $project, ConnectGitHubAction $action): RedirectResponse
    {
        try {
            $connection = $action->execute($project, $request->credentials());
        } catch (GitHubExceptionInterface $e) {
            throw ValidationException::withMessages(['token' => $e->getMessage()]);
        }

        return to_route('connectors.github.index', [$project, $connection])
            ->with('status', __('connectors.github.connected', ['name' => $connection->name]));
    }
}
