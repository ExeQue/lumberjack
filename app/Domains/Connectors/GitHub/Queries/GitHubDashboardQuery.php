<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Queries;

use App\Domains\Connectors\GitHub\Actions\PullGitHubSourceAction;
use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\GitHub\Enums\GitHubFigure;
use App\Domains\Connectors\GitHub\Enums\GitHubPermission;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\StatisticsCache;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * What the GitHub page shows: a repository's pull requests, workflow runs and releases.
 * Each is cached as a statistics page's figures are, so a reloaded page asks GitHub again
 * only once the period's TTL is up.
 */
class GitHubDashboardQuery
{
    public function __construct(
        private readonly GitHubClient $client,
    ) {
    }

    /**
     * The open pull requests and issues, stars, forks and watchers as they are now, the pull
     * requests merged, their median lead time and the runs failed over the period and the
     * period before (on the branch read, if one is chosen), the branch's latest run and the
     * latest release, and whether the repository is private.
     *
     * @return array{private: bool, open_pull_requests: int, waiting_for_review: int, open_issues: int, stars: int, forks: int, watchers: int, merged: array{current: int, previous: int}, lead_time_hours: array{current: float|null, previous: float|null}, failed_runs: array{current: int, previous: int}, ci: array<string, mixed>|null, latest_release: array<string, mixed>|null}
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException when the token was saved under another APP_KEY
     */
    public function summary(DataSourceConnection $connection, Period $period): array
    {
        // Keyed apart from the summaries cached before they said whether the repository is private.
        return StatisticsCache::remember('github-summary', $period, [$connection->id, $connection->url, GitHubConfig::branchOf($connection->config), 'private'], function () use ($connection, $period): array {
            $credentials = $connection->gitHubCredentials();
            $to = now();
            $from = $period->since();
            $before = $from->subSeconds($to->getTimestamp() - $from->getTimestamp());
            $this->client->prefetch($credentials, GitHubClient::summaryCalls($credentials, $before, $from, $to));
            $repository = $this->client->repository($credentials);
            $open = $this->client->openPullRequests($credentials);
            $run = $this->client->branchRun($credentials, $credentials->branch ?? $repository['default_branch']);
            $merged = $this->client->mergedPullRequests($credentials, $from, $to);
            $mergedBefore = $this->client->mergedPullRequests($credentials, $before, $from);

            return [
                'private' => $repository['private'],
                'open_pull_requests' => $open,
                'waiting_for_review' => $this->client->waitingForReview($credentials),
                'open_issues' => max(0, $repository['open_issues_count'] - $open),
                'stars' => $repository['stars'],
                'forks' => $repository['forks'],
                'watchers' => $repository['watchers'],
                'merged' => ['current' => $merged['total'], 'previous' => $mergedBefore['total']],
                'lead_time_hours' => ['current' => $merged['lead_time_hours'], 'previous' => $mergedBefore['lead_time_hours']],
                'failed_runs' => ['current' => $this->client->failedRuns($credentials, $from, $to), 'previous' => $this->client->failedRuns($credentials, $before, $from)],
                'ci' => $run === null ? null : [...$run, 'result_label' => PullGitHubSourceAction::result($run['result'])],
                'latest_release' => $this->client->latestRelease($credentials),
            ];
        });
    }

    /**
     * Where each key figure is on GitHub: its list, searched for the period (in UTC) and the
     * branch read where it counts those; the CI status and the latest release link their own
     * page. Asks GitHub nothing.
     *
     * @param  array{ci: array<string, mixed>|null, latest_release: array<string, mixed>|null}  $summary
     * @return array<string, string> by GitHubFigure value
     *
     * @throws DecryptException
     */
    public function links(DataSourceConnection $connection, Period $period, array $summary): array
    {
        $credentials = $connection->gitHubCredentials();
        $base = 'https://github.com/'.implode('/', array_map(rawurlencode(...), explode('/', $credentials->repository)));
        $range = GitHubClient::range($period->since(), now());
        $search = fn (string $path, string $key, string $q): string => $base.$path.'?'.http_build_query([$key => $q], '', '&', PHP_QUERY_RFC3986);
        $merged = $search('/pulls', 'q', 'is:pr is:merged merged:'.$range);

        return array_filter([
            GitHubFigure::OpenPullRequests->value => $base.'/pulls',
            GitHubFigure::WaitingForReview->value => $search('/pulls', 'q', 'is:pr is:open draft:false review:none'),
            GitHubFigure::Merged->value => $merged,
            GitHubFigure::LeadTime->value => $merged,
            GitHubFigure::Commits->value => $base.'/commits'.($credentials->branch === null ? '' : '/'.implode('/', array_map(rawurlencode(...), explode('/', $credentials->branch)))),
            GitHubFigure::Authors->value => $base.'/graphs/contributors',
            GitHubFigure::Ci->value => is_string($summary['ci']['url'] ?? null) ? $summary['ci']['url'] : null,
            GitHubFigure::FailedRuns->value => $search('/actions', 'query', 'is:failure'.($credentials->branch === null ? '' : ' branch:'.$credentials->branch)),
            GitHubFigure::LatestRelease->value => is_string($summary['latest_release']['url'] ?? null) ? $summary['latest_release']['url'] : null,
            GitHubFigure::OpenIssues->value => $base.'/issues',
            GitHubFigure::IssuesOpened->value => $search('/issues', 'q', 'is:issue created:'.$range),
            GitHubFigure::IssuesClosed->value => $search('/issues', 'q', 'is:issue is:closed closed:'.$range),
            GitHubFigure::OpenBugs->value => $search('/issues', 'q', 'is:issue is:open label:bug'),
            GitHubFigure::Stars->value => $base.'/stargazers',
            GitHubFigure::Forks->value => $base.'/forks',
            GitHubFigure::Watchers->value => $base.'/watchers',
            GitHubFigure::DependabotAlerts->value => $base.'/security/dependabot',
            GitHubFigure::CodeScanningAlerts->value => $base.'/security/code-scanning',
            GitHubFigure::SecretScanningAlerts->value => $base.'/security/secret-scanning',
        ], fn (?string $url): bool => $url !== null && $url !== '');
    }

    /**
     * The commits over the period on the branch read (the chosen one, or the default branch): how many against the period before, the
     * distinct authors, a count per bucket of the period, the authors with the most commits
     * and the latest commits. One list of the period's commits answers all but the count
     * before, which is asked on its own. It is cached whole, so showing more of either list
     * asks GitHub nothing.
     *
     * @return array{count: array{current: int, previous: int}, authors: int, series: list<array{t: string, commits: int}>, contributors: list<array{author: string, commits: int}>, latest: list<array{sha: string, message: string, author: string, url: string, committed_at: string}>, capped: bool}
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function commits(DataSourceConnection $connection, Period $period, int $latest = GitHubClient::ROWS, int $contributors = PullGitHubSourceAction::LIST_ROWS): array
    {
        $commits = StatisticsCache::remember('github-commits', $period, [$connection->id, $connection->url, GitHubConfig::branchOf($connection->config)], function () use ($connection, $period): array {
            $credentials = $connection->gitHubCredentials();
            $branch = $this->client->branch($credentials);
            $to = now();
            $from = $period->since();
            $before = $from->subSeconds($to->getTimestamp() - $from->getTimestamp());
            $this->client->prefetch($credentials, GitHubClient::commitCalls($credentials, $branch, $before, $from, $to));
            $commits = $this->client->commits($credentials, $branch, $from, $to);
            $contributors = PullGitHubSourceAction::contributors($commits);
            $series = PullGitHubSourceAction::perBucket($commits, $from, $to, $period->bucketSeconds(), $connection->timezone);

            return [
                'count' => ['current' => count($commits), 'previous' => $this->client->commitCount($credentials, $branch, $before, $from)],
                'authors' => count($contributors),
                'series' => array_values(collect($series)->map(fn (int $count, int $at): array => ['t' => gmdate('Y-m-d\TH:i:s\Z', $at), 'commits' => $count])->all()),
                'contributors' => collect($contributors)->map(fn (int $count, int|string $author): array => ['author' => (string) $author, 'commits' => $count])->values()->all(),
                'latest' => $commits,
                'capped' => count($commits) >= GitHubClient::MAX_PAGES * GitHubClient::PER_PAGE,
            ];
        });

        return [...$commits, 'contributors' => array_slice($commits['contributors'], 0, $contributors), 'latest' => array_slice($commits['latest'], 0, $latest)];
    }

    /**
     * Issues opened and closed over the period against the period before, and the open bugs;
     * null when the token does not read issues.
     *
     * @return array{opened: array{current: int, previous: int}, closed: array{current: int, previous: int}, open_bugs: int}|null
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function issues(DataSourceConnection $connection, Period $period): ?array
    {
        if (! in_array(GitHubPermission::Issues, GitHubConfig::grantedBy($connection->config), true)) {
            return null;
        }

        return StatisticsCache::remember('github-issues', $period, [$connection->id, $connection->url], function () use ($connection, $period): array {
            $credentials = $connection->gitHubCredentials();
            $to = now();
            $from = $period->since();
            $before = $from->subSeconds($to->getTimestamp() - $from->getTimestamp());
            $this->client->prefetch($credentials, GitHubClient::issueCalls($credentials, $before, $from, $to));

            return [
                'opened' => ['current' => $this->client->issues($credentials, 'created', $from, $to), 'previous' => $this->client->issues($credentials, 'created', $before, $from)],
                'closed' => ['current' => $this->client->issues($credentials, 'closed', $from, $to), 'previous' => $this->client->issues($credentials, 'closed', $before, $from)],
                'open_bugs' => $this->client->openBugs($credentials),
            ];
        });
    }

    /**
     * The open alerts of each security feature the token reads: a count, Dependabot's also
     * by severity, or why there is none, so a feature turned off does not fail the page.
     *
     * @return list<array{kind: string, label: string, granted: bool, count: int|null, by_severity: array<string, int>|null, error: string|null}>
     *
     * @throws DecryptException
     */
    public function security(DataSourceConnection $connection): array
    {
        return StatisticsCache::remember('github-security', Period::Day, [$connection->id, $connection->url, ...array_map(fn (GitHubPermission $p): string => $p->value, GitHubConfig::grantedBy($connection->config))], function () use ($connection): array {
            $credentials = $connection->gitHubCredentials();
            $granted = GitHubConfig::grantedBy($connection->config);
            $all = [GitHubPermission::DependabotAlerts, GitHubPermission::CodeScanningAlerts, GitHubPermission::SecretScanningAlerts];
            $kinds = [];
            $this->client->prefetch($credentials, GitHubClient::alertCalls($credentials, array_filter($all, fn (GitHubPermission $kind): bool => in_array($kind, $granted, true))));

            foreach ($all as $kind) {
                $row = ['kind' => $kind->value, 'label' => $kind->label(), 'granted' => in_array($kind, $granted, true), 'count' => null, 'by_severity' => null, 'error' => null];

                if ($row['granted']) {
                    try {
                        $alerts = $this->client->openAlerts($credentials, $kind);
                        $row['count'] = count($alerts);
                        $row['by_severity'] = $kind === GitHubPermission::DependabotAlerts ? GitHubClient::bySeverity($alerts) : null;
                    } catch (GitHubRequestFailedException $e) {
                        $row['error'] = $e->getMessage();
                    }
                }

                $kinds[] = $row;
            }

            return $kinds;
        });
    }

    /**
     * The repository's default branch, and its branches with the default first and the rest
     * alphabetically, for the branch field. Cached as the lists are.
     *
     * @return array{default_branch: string, branches: list<string>}
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function branches(DataSourceConnection $connection): array
    {
        return StatisticsCache::remember('github-branches', Period::Day, [$connection->id, $connection->url], function () use ($connection): array {
            $credentials = $connection->gitHubCredentials();
            $default = $this->client->repository($credentials)['default_branch'];

            return [
                'default_branch' => $default,
                'branches' => [$default, ...collect($this->client->branches($credentials))
                    ->reject(fn (string $name): bool => $name === $default)
                    ->sort(strnatcasecmp(...))
                    ->all()],
            ];
        });
    }

    /**
     * Each optional permission, whether the token had it when last connected, and what it adds.
     *
     * @return list<array{key: string, label: string, unlocks: string, granted: bool}>
     */
    public function permissions(?GitHubConfig $config): array
    {
        return array_map(fn (GitHubPermission $permission): array => [
            'key' => $permission->value,
            'label' => $permission->label(),
            'unlocks' => $permission->unlocks(),
            'granted' => in_array($permission, GitHubConfig::grantedBy($config), true),
        ], GitHubPermission::cases());
    }

    /**
     * @return list<array{number: int, title: string, author: string, draft: bool, url: string, created_at: string|null}>
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function pullRequests(DataSourceConnection $connection, int $rows = GitHubClient::ROWS): array
    {
        return StatisticsCache::remember('github-pulls', Period::Day, [$connection->id, $connection->url, $rows], fn (): array => $this->client->pullRequests($connection->gitHubCredentials(), $rows));
    }

    /**
     * The latest workflow runs, each with what it came to in words.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function runs(DataSourceConnection $connection, int $rows = GitHubClient::ROWS): array
    {
        return StatisticsCache::remember('github-runs', Period::Day, [$connection->id, $connection->url, $rows], fn (): array => array_map(
            fn (array $run): array => [...$run, 'result_label' => PullGitHubSourceAction::result($run['result'])],
            $this->client->runs($connection->gitHubCredentials(), rows: $rows)['runs'],
        ));
    }

    /**
     * @return list<array{name: string, tag: string, prerelease: bool, url: string, published_at: string|null, body: string}>
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function releases(DataSourceConnection $connection, int $rows = GitHubClient::ROWS): array
    {
        return StatisticsCache::remember('github-releases', Period::Day, [$connection->id, $connection->url, $rows], fn (): array => $this->client->releases($connection->gitHubCredentials(), $rows));
    }
}
