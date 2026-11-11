<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Queries;

use App\Domains\Connectors\GitHub\Actions\PullGitHubSourceAction;
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
     * The open pull requests and issues as they are now, the pull requests merged and the
     * runs failed over the period and the period before, the default branch's latest run
     * and the latest release.
     *
     * @return array{open_pull_requests: int, waiting_for_review: int, open_issues: int, merged: array{current: int, previous: int}, failed_runs: array{current: int, previous: int}, ci: array<string, mixed>|null, latest_release: array<string, mixed>|null}
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException when the token was saved under another APP_KEY
     */
    public function summary(DataSourceConnection $connection, Period $period): array
    {
        return StatisticsCache::remember('github-summary', $period, [$connection->id, $connection->url], function () use ($connection, $period): array {
            $credentials = $connection->gitHubCredentials();
            $repository = $this->client->repository($credentials);
            $open = $this->client->openPullRequests($credentials);
            $to = now();
            $from = $period->since();
            $before = $from->subSeconds($to->getTimestamp() - $from->getTimestamp());
            $run = $this->client->defaultBranchRun($credentials, $repository['default_branch']);

            return [
                'open_pull_requests' => $open,
                'waiting_for_review' => $this->client->waitingForReview($credentials),
                'open_issues' => max(0, $repository['open_issues_count'] - $open),
                'merged' => ['current' => $this->client->merged($credentials, $from, $to), 'previous' => $this->client->merged($credentials, $before, $from)],
                'failed_runs' => ['current' => $this->client->failedRuns($credentials, $from, $to), 'previous' => $this->client->failedRuns($credentials, $before, $from)],
                'ci' => $run === null ? null : [...$run, 'result_label' => PullGitHubSourceAction::result($run['result'])],
                'latest_release' => $this->client->latestRelease($credentials),
            ];
        });
    }

    /**
     * @return list<array{number: int, title: string, author: string, draft: bool, url: string, created_at: string|null}>
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function pullRequests(DataSourceConnection $connection): array
    {
        return StatisticsCache::remember('github-pulls', Period::Day, [$connection->id, $connection->url], fn (): array => $this->client->pullRequests($connection->gitHubCredentials()));
    }

    /**
     * The latest workflow runs, each with what it came to in words.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function runs(DataSourceConnection $connection): array
    {
        return StatisticsCache::remember('github-runs', Period::Day, [$connection->id, $connection->url], fn (): array => array_map(
            fn (array $run): array => [...$run, 'result_label' => PullGitHubSourceAction::result($run['result'])],
            $this->client->runs($connection->gitHubCredentials())['runs'],
        ));
    }

    /**
     * @return list<array{name: string, tag: string, prerelease: bool, url: string, published_at: string|null}>
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function releases(DataSourceConnection $connection): array
    {
        return StatisticsCache::remember('github-releases', Period::Day, [$connection->id, $connection->url], fn (): array => $this->client->releases($connection->gitHubCredentials()));
    }
}
