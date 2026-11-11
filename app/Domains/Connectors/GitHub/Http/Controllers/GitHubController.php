<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Http\Controllers;

use App\Domains\Connectors\GitHub\Exceptions\GitHubExceptionInterface;
use App\Domains\Connectors\GitHub\Queries\GitHubDashboardQuery;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's GitHub repository: its pull requests, workflow runs and releases, with the
 * pull requests merged and the runs failed over the period against the period before.
 */
class GitHubController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, GitHubDashboardQuery $query, ProjectPresenter $presenter, ?DataSourceConnection $dataSourceConnection = null): Response
    {
        Gate::authorize('view', $project);
        abort_if($dataSourceConnection?->isFor(ConnectionKind::GitHub) === false, 404);
        $connections = $project->gitHubConnections()->get();
        $connection = $dataSourceConnection ?? $connections->first();
        $period = $this->period($request, Period::Week);
        $error = null;
        $summary = null;
        $pullRequests = [];
        $runs = [];
        $releases = [];

        if ($connection !== null) {
            try {
                $summary = $query->summary($connection, $period);
                $pullRequests = $query->pullRequests($connection);
                $runs = $query->runs($connection);
                $releases = $query->releases($connection);
            } catch (GitHubExceptionInterface $e) {
                $error = $e->getMessage();
            } catch (DecryptException) {
                $error = __('connectors.github.errors.secret_unreadable');
            }
        }

        return Inertia::render('connectors/github/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => ['period' => $period->value, 'environment' => null],
            'connection' => $connection === null ? null : [
                'uuid' => $connection->uuid,
                'name' => $connection->displayName(),
                'url' => $connection->url,
            ],
            // Every repository the project reads, to switch between.
            'repositories' => $connections->map(fn (DataSourceConnection $c): array => ['uuid' => $c->uuid, 'name' => $c->displayName()])->all(),
            'error' => $error,
            'summary' => $summary,
            'pull_requests' => $pullRequests,
            'runs' => $runs,
            'releases' => $releases,
        ]);
    }
}
