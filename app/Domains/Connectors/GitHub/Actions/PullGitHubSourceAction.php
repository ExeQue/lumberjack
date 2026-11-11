<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Actions;

use App\Domains\Connectors\GitHub\Enums\GitHubPermission;
use App\Domains\Connectors\GitHub\Enums\GitHubSource;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\StatisticsCache;
use Carbon\CarbonImmutable;

/**
 * One of a GitHub repository's sources over a range, as an app would answer for it. The
 * open counts, the CI status, the latest release, the open pull requests, stars, forks,
 * watchers and alerts are as they are now, whatever the range. The commit sources read the default branch's commits in the range
 * once between them.
 */
class PullGitHubSourceAction
{
    /** Rows a list source answers. */
    public const int LIST_ROWS = 10;

    public function __construct(
        private readonly GitHubClient $client,
    ) {
    }

    /**
     * @return array{shape: string, data: mixed}
     *
     * @throws GitHubRequestFailedException
     */
    public function execute(DataSource $source, CarbonImmutable $from, CarbonImmutable $to, int $bucket, string $timezone): array
    {
        $connection = $source->dataSourceConnection ?? throw new GitHubRequestFailedException(__('connectors.github.errors.unknown_source'));
        $kind = GitHubSource::tryFrom((string) $source->key) ?? throw new GitHubRequestFailedException(__('connectors.github.errors.unknown_source'));
        $credentials = $connection->gitHubCredentials();

        // The range ends on a step, so the sources a screen shows for it ask GitHub once.
        $commits = $kind->readsCommits()
            ? StatisticsCache::remember('github-commits-range', Period::Day, [$connection->id, $connection->url, $from->getTimestamp(), $to->getTimestamp()], fn (): array => $this->client->commits($credentials, $this->client->repository($credentials)['default_branch'], $from, $to))
            : [];

        $data = match ($kind) {
            GitHubSource::OpenPullRequests => $this->client->openPullRequests($credentials),
            GitHubSource::WaitingForReview => $this->client->waitingForReview($credentials),
            GitHubSource::Merged => $this->client->merged($credentials, $from, $to),
            GitHubSource::FailedRuns => $this->client->failedRuns($credentials, $from, $to),
            GitHubSource::OpenIssues => $this->client->openIssues($credentials),
            GitHubSource::CiStatus => self::ciStatus($this->client->defaultBranchRun($credentials)),
            GitHubSource::LatestRelease => self::latestRelease($this->client->latestRelease($credentials)),
            GitHubSource::Commits => count($commits),
            GitHubSource::CommitsSeries => array_map(fn (int $at, int $count): array => ['at' => $at, 'value' => $count], array_keys($series = self::perBucket($commits, $from, $to, $bucket, $timezone)), $series),
            GitHubSource::Authors => count(self::contributors($commits)),
            GitHubSource::LatestCommits => array_map(fn (array $commit): array => [
                'label' => $commit['message'].' ('.$commit['author'].')',
                'value' => CarbonImmutable::parse($commit['committed_at'])->setTimezone($timezone)->format('Y-m-d H:i'),
            ], array_slice($commits, 0, self::LIST_ROWS)),
            GitHubSource::TopContributors => array_map(fn (string $author, int $count): array => ['label' => $author, 'value' => $count], array_keys($top = array_slice(self::contributors($commits), 0, self::LIST_ROWS, true)), $top),
            GitHubSource::PullRequestList => array_map(fn (array $pull): array => [
                'label' => '#'.$pull['number'].' '.$pull['title'].' ('.$pull['author'].($pull['draft'] ? ', '.__('connectors.github.draft') : '').')',
                'value' => $pull['created_at'] === null ? '' : CarbonImmutable::parse($pull['created_at'])->setTimezone($timezone)->format('Y-m-d'),
            ], array_slice($this->client->pullRequests($credentials), 0, self::LIST_ROWS)),
            GitHubSource::LeadTime => $this->client->mergedPullRequests($credentials, $from, $to)['lead_time_hours'],
            GitHubSource::Stars => $this->client->repository($credentials)['stars'],
            GitHubSource::Forks => $this->client->repository($credentials)['forks'],
            GitHubSource::Watchers => $this->client->repository($credentials)['watchers'],
            GitHubSource::IssuesOpened => $this->client->issues($credentials, 'created', $from, $to),
            GitHubSource::IssuesClosed => $this->client->issues($credentials, 'closed', $from, $to),
            GitHubSource::OpenBugs => $this->client->openBugs($credentials),
            GitHubSource::DependabotAlerts => count($this->client->openAlerts($credentials, GitHubPermission::DependabotAlerts)),
            GitHubSource::DependabotBySeverity => array_map(
                fn (string $severity, int $count): array => ['label' => __('connectors.github.severities.'.$severity), 'value' => $count],
                GitHubClient::SEVERITIES,
                array_values(GitHubClient::bySeverity($this->client->openAlerts($credentials, GitHubPermission::DependabotAlerts))),
            ),
            GitHubSource::CodeScanningAlerts => count($this->client->openAlerts($credentials, GitHubPermission::CodeScanningAlerts)),
            GitHubSource::SecretScanningAlerts => count($this->client->openAlerts($credentials, GitHubPermission::SecretScanningAlerts)),
        };

        return ['shape' => $kind->shape()->value, 'data' => $data];
    }

    /**
     * The run as one row: its workflow and what it came to.
     *
     * @param  array{name: string, branch: string, result: string}|null  $run
     * @return list<array{label: string, value: string}>
     */
    private static function ciStatus(?array $run): array
    {
        return $run === null ? [] : [['label' => $run['name'].' ('.$run['branch'].')', 'value' => self::result($run['result'])]];
    }

    /**
     * The release as one row: its name and the day it came out, empty for a tag.
     *
     * @param  array{name: string, published_at: string|null}|null  $release
     * @return list<array{label: string, value: string}>
     */
    private static function latestRelease(?array $release): array
    {
        return $release === null ? [] : [['label' => $release['name'], 'value' => $release['published_at'] === null ? '' : CarbonImmutable::parse($release['published_at'])->format('Y-m-d')]];
    }

    /**
     * Commits per bucket of the range, by the bucket's start, each bucket there with 0 when
     * nothing was committed in it. Buckets of a day or more begin at midnight in the zone.
     *
     * ponytail: a day with a DST change gets a bucket an hour off; days themselves stay right.
     *
     * @param  list<array{committed_at: string}>  $commits
     * @return array<int, int>
     */
    public static function perBucket(array $commits, CarbonImmutable $from, CarbonImmutable $to, int $bucket, string $timezone): array
    {
        $start = function (CarbonImmutable $at) use ($bucket, $timezone): int {
            $midnight = $at->setTimezone($timezone)->startOfDay()->getTimestamp();

            return $bucket >= 86_400 ? $midnight : $midnight + intdiv($at->getTimestamp() - $midnight, $bucket) * $bucket;
        };
        $counts = [];

        for ($at = $start($from); $at < $to->getTimestamp(); $at = $bucket >= 86_400 ? CarbonImmutable::createFromTimestamp($at, $timezone)->addDay()->getTimestamp() : $at + $bucket) {
            $counts[$at] = 0;
        }

        foreach ($commits as $commit) {
            $at = $start(CarbonImmutable::parse($commit['committed_at']));

            if (array_key_exists($at, $counts)) {
                $counts[$at]++;
            }
        }

        return $counts;
    }

    /**
     * Each author with their commits, the most first.
     *
     * @param  list<array{author: string}>  $commits
     * @return array<string, int>
     */
    public static function contributors(array $commits): array
    {
        $counts = array_count_values(array_column($commits, 'author'));
        arsort($counts);

        // array_count_values() turns a numeric name into an int key.
        return array_combine(array_map(strval(...), array_keys($counts)), $counts);
    }

    /** What a run came to, in words; one GitHub adds later reads as it says it. */
    public static function result(string $result): string
    {
        $key = 'connectors.github.results.'.$result;

        return trans()->has($key) ? __($key) : str_replace('_', ' ', $result);
    }
}
