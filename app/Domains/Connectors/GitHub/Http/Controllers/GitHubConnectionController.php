<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Http\Controllers;

use App\Domains\Connectors\GitHub\Actions\ChangeGitHubBranchAction;
use App\Domains\Connectors\GitHub\Actions\ConnectGitHubAction;
use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\GitHub\Enums\GitHubPermission;
use App\Domains\Connectors\GitHub\Exceptions\GitHubBranchNotFoundException;
use App\Domains\Connectors\GitHub\Exceptions\GitHubExceptionInterface;
use App\Domains\Connectors\GitHub\Http\Requests\ChangeGitHubBranchRequest;
use App\Domains\Connectors\GitHub\Http\Requests\ConnectGitHubRequest;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a project to a GitHub repository, and changing the branch it is read from;
 * the token never leaves the server again.
 */
class GitHubConnectionController
{
    public function store(ConnectGitHubRequest $request, Project $project, ConnectGitHubAction $action): RedirectResponse
    {
        try {
            $connection = $action->execute($project, $request->credentials());
        } catch (GitHubBranchNotFoundException $e) {
            throw ValidationException::withMessages(['branch' => $e->getMessage()]);
        } catch (GitHubExceptionInterface $e) {
            throw ValidationException::withMessages(['token' => $e->getMessage()]);
        }

        $missing = array_filter(GitHubPermission::cases(), fn (GitHubPermission $permission): bool => ! in_array($permission, GitHubConfig::grantedBy($connection->config), true));

        return to_route('connectors.github.index', [$project, $connection])
            ->with('status', $missing === []
                ? __('connectors.github.connected', ['name' => $connection->name])
                : __('connectors.github.connected_missing', ['name' => $connection->name, 'permissions' => implode(', ', array_map(fn (GitHubPermission $permission): string => $permission->label(), $missing))]));
    }

    public function update(ChangeGitHubBranchRequest $request, Project $project, DataSourceConnection $dataSourceConnection, ChangeGitHubBranchAction $action): RedirectResponse
    {
        abort_unless($dataSourceConnection->isFor(ConnectionKind::GitHub), 404);
        Gate::authorize('update', $dataSourceConnection);
        $branch = ChangeGitHubBranchRequest::branchOf($request);

        try {
            $action->execute($dataSourceConnection, $branch);
        } catch (GitHubExceptionInterface $e) {
            throw ValidationException::withMessages(['branch' => $e->getMessage()]);
        } catch (DecryptException) {
            throw ValidationException::withMessages(['branch' => __('connectors.github.errors.secret_unreadable')]);
        }

        return back()->with('status', $branch === null
            ? __('connectors.github.branch_default')
            : __('connectors.github.branch_changed', ['branch' => $branch]));
    }
}
