<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Actions;

use App\Domains\Connectors\GitHub\Enums\GitHubSource;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use App\Domains\DataSources\Models\DataSource;
use Carbon\CarbonImmutable;

/**
 * One of a GitHub repository's sources over a range, as an app would answer for it. The
 * open counts, the CI status and the latest release are as they are now, whatever the range.
 */
class PullGitHubSourceAction
{
    public function __construct(
        private readonly GitHubClient $client,
    ) {
    }

    /**
     * @return array{shape: string, data: mixed}
     *
     * @throws GitHubRequestFailedException
     */
    public function execute(DataSource $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $connection = $source->dataSourceConnection ?? throw new GitHubRequestFailedException(__('connectors.github.errors.unknown_source'));
        $kind = GitHubSource::tryFrom((string) $source->key) ?? throw new GitHubRequestFailedException(__('connectors.github.errors.unknown_source'));
        $credentials = $connection->gitHubCredentials();

        $data = match ($kind) {
            GitHubSource::OpenPullRequests => $this->client->openPullRequests($credentials),
            GitHubSource::WaitingForReview => $this->client->waitingForReview($credentials),
            GitHubSource::Merged => $this->client->merged($credentials, $from, $to),
            GitHubSource::FailedRuns => $this->client->failedRuns($credentials, $from, $to),
            GitHubSource::OpenIssues => $this->client->openIssues($credentials),
            GitHubSource::CiStatus => self::ciStatus($this->client->defaultBranchRun($credentials)),
            GitHubSource::LatestRelease => self::latestRelease($this->client->latestRelease($credentials)),
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

    /** What a run came to, in words; one GitHub adds later reads as it says it. */
    public static function result(string $result): string
    {
        $key = 'connectors.github.results.'.$result;

        return trans()->has($key) ? __($key) : str_replace('_', ' ', $result);
    }
}
