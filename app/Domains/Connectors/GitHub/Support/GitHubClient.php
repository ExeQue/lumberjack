<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Support;

use App\Domains\Connectors\GitHub\Data\GitHubCredentials;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * GitHub's REST API (docs.github.com/rest), read for one repository with a fine-grained
 * token. Counts come from the search API, which answers them in one call but allows 30
 * calls a minute, so they are only asked while a page or a screen shows them.
 */
class GitHubClient
{
    public const string API = 'https://api.github.com';

    public const int TIMEOUT_S = 15;

    /** Rows a list on the page shows. */
    public const int ROWS = 20;

    /**
     * The repository as GitHub names it, its default branch, and its open issues (pull
     * requests included, as GitHub counts them). Reading it needs Metadata only.
     *
     * @return array{full_name: string, default_branch: string, open_issues_count: int}
     *
     * @throws GitHubRequestFailedException
     */
    public function repository(GitHubCredentials $credentials): array
    {
        $data = $this->get($credentials, '/repos/'.$credentials->repository);

        if (! is_string($data['full_name'] ?? null)) {
            throw new GitHubRequestFailedException(__('connectors.github.errors.not_found', ['repository' => $credentials->repository]));
        }

        return [
            'full_name' => $data['full_name'],
            'default_branch' => is_string($data['default_branch'] ?? null) ? $data['default_branch'] : 'main',
            'open_issues_count' => is_int($data['open_issues_count'] ?? null) ? $data['open_issues_count'] : 0,
        ];
    }

    /**
     * Whether the token reads the repository and everything the connector shows: one call
     * for each permission, so a missing one is named.
     *
     * @return array{full_name: string, default_branch: string, open_issues_count: int}
     *
     * @throws GitHubRequestFailedException
     */
    public function verify(GitHubCredentials $credentials): array
    {
        $repository = $this->repository($credentials);
        $path = '/repos/'.$credentials->repository;

        $this->get($credentials, $path.'/pulls', ['per_page' => 1], 'Pull requests');
        $this->get($credentials, $path.'/actions/runs', ['per_page' => 1], 'Actions');
        $this->get($credentials, $path.'/releases', ['per_page' => 1], 'Contents');

        return $repository;
    }

    /**
     * How many issues and pull requests of the repository match a search, such as
     * "is:pr is:open".
     *
     * @throws GitHubRequestFailedException
     */
    public function count(GitHubCredentials $credentials, string $query): int
    {
        $data = $this->get($credentials, '/search/issues', ['q' => 'repo:'.$credentials->repository.' '.$query, 'per_page' => 1], 'Pull requests');

        return is_int($data['total_count'] ?? null) ? $data['total_count'] : 0;
    }

    /** @throws GitHubRequestFailedException */
    public function openPullRequests(GitHubCredentials $credentials): int
    {
        return $this->count($credentials, 'is:pr is:open');
    }

    /**
     * Open pull requests that are ready and nobody has reviewed yet. Search cannot ask for
     * "review requested or no review", so a pull request already reviewed once and waiting
     * for another round is not counted.
     *
     * @throws GitHubRequestFailedException
     */
    public function waitingForReview(GitHubCredentials $credentials): int
    {
        return $this->count($credentials, 'is:pr is:open draft:false review:none');
    }

    /** @throws GitHubRequestFailedException */
    public function merged(GitHubCredentials $credentials, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->count($credentials, 'is:pr is:merged merged:'.self::range($from, $to));
    }

    /**
     * Open issues without the pull requests GitHub counts among them, so the Issues
     * permission is not needed.
     *
     * @throws GitHubRequestFailedException
     */
    public function openIssues(GitHubCredentials $credentials, ?int $openPullRequests = null): int
    {
        return max(0, $this->repository($credentials)['open_issues_count'] - ($openPullRequests ?? $this->openPullRequests($credentials)));
    }

    /** @throws GitHubRequestFailedException */
    public function failedRuns(GitHubCredentials $credentials, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->runs($credentials, ['status' => 'failure', 'created' => self::range($from, $to)], 1)['total'];
    }

    /**
     * The latest workflow run on the default branch, from a push or otherwise; the branch
     * is asked of GitHub unless given.
     *
     * @return array{name: string, title: string, branch: string, event: string, status: string, conclusion: string|null, result: string, url: string, created_at: string|null}|null
     *
     * @throws GitHubRequestFailedException
     */
    public function defaultBranchRun(GitHubCredentials $credentials, ?string $branch = null): ?array
    {
        $branch ??= $this->repository($credentials)['default_branch'];

        return $this->runs($credentials, ['branch' => $branch, 'exclude_pull_requests' => 'true'], 1)['runs'][0] ?? null;
    }

    /**
     * Workflow runs, the latest first, and how many match in all.
     *
     * @param  array<string, string>  $filters
     * @return array{total: int, runs: list<array{name: string, title: string, branch: string, event: string, status: string, conclusion: string|null, result: string, url: string, created_at: string|null}>}
     *
     * @throws GitHubRequestFailedException
     */
    public function runs(GitHubCredentials $credentials, array $filters = [], int $rows = self::ROWS): array
    {
        $data = $this->get($credentials, '/repos/'.$credentials->repository.'/actions/runs', [...$filters, 'per_page' => $rows], 'Actions');
        $runs = [];

        foreach (self::listOf($data['workflow_runs'] ?? null) as $run) {
            $status = self::text($run['status'] ?? null);
            $conclusion = is_string($run['conclusion'] ?? null) ? $run['conclusion'] : null;
            $runs[] = [
                'name' => self::text($run['name'] ?? null),
                'title' => self::text($run['display_title'] ?? null),
                'branch' => self::text($run['head_branch'] ?? null),
                'event' => self::text($run['event'] ?? null),
                'status' => $status,
                'conclusion' => $conclusion,
                // What the run came to, or how far it got while it runs.
                'result' => $conclusion ?? $status,
                'url' => self::text($run['html_url'] ?? null),
                'created_at' => is_string($run['created_at'] ?? null) ? $run['created_at'] : null,
            ];
        }

        return ['total' => is_int($data['total_count'] ?? null) ? $data['total_count'] : count($runs), 'runs' => $runs];
    }

    /**
     * Open pull requests, the newest first.
     *
     * @return list<array{number: int, title: string, author: string, draft: bool, url: string, created_at: string|null}>
     *
     * @throws GitHubRequestFailedException
     */
    public function pullRequests(GitHubCredentials $credentials): array
    {
        $data = $this->get($credentials, '/repos/'.$credentials->repository.'/pulls', ['state' => 'open', 'per_page' => self::ROWS], 'Pull requests');

        return array_map(fn (array $pull): array => [
            'number' => is_int($pull['number'] ?? null) ? $pull['number'] : 0,
            'title' => self::text($pull['title'] ?? null),
            'author' => is_array($pull['user'] ?? null) ? self::text($pull['user']['login'] ?? null) : '',
            'draft' => ($pull['draft'] ?? false) === true,
            'url' => self::text($pull['html_url'] ?? null),
            'created_at' => is_string($pull['created_at'] ?? null) ? $pull['created_at'] : null,
        ], self::listOf($data));
    }

    /**
     * Published releases, the newest first. Drafts are left out; a read-only token does not
     * see them anyway.
     *
     * @return list<array{name: string, tag: string, prerelease: bool, url: string, published_at: string|null}>
     *
     * @throws GitHubRequestFailedException
     */
    public function releases(GitHubCredentials $credentials, int $rows = self::ROWS): array
    {
        $data = $this->get($credentials, '/repos/'.$credentials->repository.'/releases', ['per_page' => $rows], 'Contents');
        $releases = [];

        foreach (self::listOf($data) as $release) {
            if (($release['draft'] ?? false) === true) {
                continue;
            }

            $tag = self::text($release['tag_name'] ?? null);
            $releases[] = [
                'name' => is_string($release['name'] ?? null) && $release['name'] !== '' ? $release['name'] : $tag,
                'tag' => $tag,
                'prerelease' => ($release['prerelease'] ?? false) === true,
                'url' => self::text($release['html_url'] ?? null),
                'published_at' => is_string($release['published_at'] ?? null) ? $release['published_at'] : null,
            ];
        }

        return $releases;
    }

    /**
     * The newest release, or else the first tag GitHub lists, which has no date.
     *
     * @return array{name: string, tag: string, prerelease: bool, url: string, published_at: string|null}|null
     *
     * @throws GitHubRequestFailedException
     */
    public function latestRelease(GitHubCredentials $credentials): ?array
    {
        $release = $this->releases($credentials, 5)[0] ?? null;

        if ($release !== null) {
            return $release;
        }

        $tag = self::listOf($this->get($credentials, '/repos/'.$credentials->repository.'/tags', ['per_page' => 1], 'Contents'))[0] ?? null;
        $name = self::text($tag['name'] ?? null);

        return $name === '' ? null : [
            'name' => $name,
            'tag' => $name,
            'prerelease' => false,
            'url' => 'https://github.com/'.$credentials->repository.'/releases/tag/'.rawurlencode($name),
            'published_at' => null,
        ];
    }

    /**
     * @param  array<string, int|string>  $query
     * @param  string|null  $permission  the token permission the call needs, named when it is refused
     * @return array<mixed>
     *
     * @throws GitHubRequestFailedException
     */
    private function get(GitHubCredentials $credentials, string $path, array $query = [], ?string $permission = null): array
    {
        try {
            $response = $this->http($credentials)->get($path, $query);
        } catch (ConnectionException $e) {
            throw new GitHubRequestFailedException(__('connectors.github.errors.unreachable'), previous: $e);
        }

        $status = $response->status();

        if ($status === 200) {
            $data = $response->json();

            return is_array($data) ? $data : throw new GitHubRequestFailedException(__('connectors.github.errors.status', ['status' => $status]));
        }

        // A rate limit is a 403 or 429 that says so in its headers.
        if ($status === 429 || ($status === 403 && ($response->header('x-ratelimit-remaining') === '0' || $response->header('retry-after') !== ''))) {
            throw new GitHubRequestFailedException(__('connectors.github.errors.rate_limited'));
        }

        // GitHub hides what a token may not read: a missing permission is a 403 or a 404,
        // and a search the token cannot run is a 422.
        throw new GitHubRequestFailedException(match (true) {
            $status === 401 => __('connectors.github.errors.unauthorized'),
            in_array($status, [403, 404, 422], true) && $permission !== null => __('connectors.github.errors.permission', ['permission' => $permission]),
            in_array($status, [403, 404], true) => __('connectors.github.errors.not_found', ['repository' => $credentials->repository]),
            default => __('connectors.github.errors.status', ['status' => $status]),
        });
    }

    private function http(GitHubCredentials $credentials): PendingRequest
    {
        return Http::retry(
            times: 3,
            sleepMilliseconds: 200,
            when: fn (?Throwable $e): bool => $e instanceof ConnectionException,
            throw: false,
        )->baseUrl(self::API)->withToken($credentials->token)->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent('Lumberjack-github/1')
            ->timeout(self::TIMEOUT_S)->connectTimeout(5);
    }

    /** A range as GitHub's search reads it, in UTC to the second. */
    private static function range(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->utc()->format('Y-m-d\TH:i:s\Z').'..'.$to->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return list<array<mixed>>
     */
    private static function listOf(mixed $data): array
    {
        return is_array($data) ? array_values(array_filter($data, is_array(...))) : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
