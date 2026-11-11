<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Http\Controllers;

use App\Domains\Connectors\GitHub\Actions\PullGitHubSourceAction;
use App\Domains\Connectors\GitHub\Exceptions\GitHubExceptionInterface;
use App\Domains\Connectors\GitHub\Queries\GitHubDashboardQuery;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
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
 * A project's GitHub repository: its pull requests, commits, workflow runs, releases, and
 * the issues and security alerts its token reads, with the period's figures against the
 * period before.
 */
class GitHubController
{
    use ReadsDashboardFilters;

    /**
     * Each list's rows at first, which is also how many each Load more adds, and the most it
     * loads: one page of GitHub's, or for the commits all the period's commits that are read.
     */
    private const array LISTS = [
        'pull_requests' => [GitHubClient::ROWS, GitHubClient::MAX_ROWS],
        'runs' => [GitHubClient::ROWS, GitHubClient::MAX_ROWS],
        'releases' => [GitHubClient::ROWS, GitHubClient::MAX_ROWS],
        'commits' => [GitHubClient::ROWS, GitHubClient::MAX_PAGES * GitHubClient::PER_PAGE],
        'contributors' => [PullGitHubSourceAction::LIST_ROWS, GitHubClient::MAX_ROWS],
    ];

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
        $commits = null;
        $issues = null;
        $security = [];

        // How many rows each list shows, as rows[list]=n, grown by its Load more.
        $rows = array_map(fn (array $list): int => $list[0], self::LISTS);

        foreach (array_intersect_key((array) $request->query('rows', []), self::LISTS) as $list => $n) {
            $rows[$list] = is_numeric($n) ? min(max((int) $n, self::LISTS[$list][0]), self::LISTS[$list][1]) : self::LISTS[$list][0];
        }

        if ($connection !== null) {
            try {
                $summary = $query->summary($connection, $period);
                $pullRequests = $query->pullRequests($connection, $rows['pull_requests']);
                $runs = $query->runs($connection, $rows['runs']);
                $releases = $query->releases($connection, $rows['releases']);
                $commits = $query->commits($connection, $period, $rows['commits'], $rows['contributors']);
                $issues = $query->issues($connection, $period);
                $security = $query->security($connection);
            } catch (GitHubExceptionInterface $e) {
                $error = $e->getMessage();
            } catch (DecryptException) {
                $error = __('connectors.github.errors.secret_unreadable');
            }
        }

        return Inertia::render('connectors/github/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => ['period' => $period->value, 'environment' => null],
            'rows' => (object) array_filter($rows, fn (int $n, string $list): bool => $n > self::LISTS[$list][0], ARRAY_FILTER_USE_BOTH),
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
            'commits' => $commits,
            'issues' => $issues,
            'security' => $security,
            // Which optional permissions the token has, for the Connection tab.
            'permissions' => $connection === null ? [] : $query->permissions($connection),
        ]);
    }
}
