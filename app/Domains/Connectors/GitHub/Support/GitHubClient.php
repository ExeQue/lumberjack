<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Support;

use App\Domains\Connectors\GitHub\Data\GitHubCredentials;
use App\Domains\Connectors\GitHub\Enums\GitHubPermission;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

    /** Items GitHub lists a page at most. */
    public const int PER_PAGE = 100;

    /**
     * Pages read for one list, such as a range's commits or the open alerts.
     *
     * ponytail: 10 pages, so a list counts at most 1000 commits or alerts; read more pages,
     * or count elsewhere, if a repository commits more than that in 30 days.
     */
    public const int MAX_PAGES = 10;

    /** The severities a Dependabot alert has, the worst first. */
    public const array SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /**
     * The repository as GitHub names it, its default branch, and its open issues (pull
     * requests included, as GitHub counts them). Reading it needs Metadata only.
     *
     * @return array{full_name: string, default_branch: string, open_issues_count: int, stars: int, forks: int, watchers: int}
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
            'stars' => is_int($data['stargazers_count'] ?? null) ? $data['stargazers_count'] : 0,
            'forks' => is_int($data['forks_count'] ?? null) ? $data['forks_count'] : 0,
            // Who watches it; GitHub's watchers_count is the stars again.
            'watchers' => is_int($data['subscribers_count'] ?? null) ? $data['subscribers_count'] : 0,
        ];
    }

    /**
     * Whether the token reads the repository and everything the connector shows: one call
     * for each permission, so a missing one is named.
     *
     * @return array{full_name: string, default_branch: string, open_issues_count: int, stars: int, forks: int, watchers: int}
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
     * The optional permissions the token has. A 403 or 404 that does not say the token lacks
     * the permission is a feature the repository has turned off, such as Dependabot alerts:
     * the token may read it, and its sources say it is off.
     *
     * @return list<GitHubPermission>
     *
     * @throws GitHubRequestFailedException when GitHub cannot be reached
     */
    public function grantedPermissions(GitHubCredentials $credentials): array
    {
        $granted = [];

        foreach (GitHubPermission::cases() as $permission) {
            try {
                $response = $this->http($credentials)->get('/repos/'.$credentials->repository.$permission->path(), ['per_page' => 1]);
            } catch (ConnectionException $e) {
                throw new GitHubRequestFailedException(__('connectors.github.errors.unreachable'), previous: $e);
            }

            $refused = in_array($response->status(), [401, 403, 404], true) && ($response->status() === 401 || str_contains((string) $response->json('message'), 'not accessible'));

            if ($response->status() === 200 || (! $refused && in_array($response->status(), [403, 404], true))) {
                $granted[] = $permission;
            }
        }

        return $granted;
    }

    /**
     * How many issues and pull requests of the repository match a search, such as
     * "is:pr is:open".
     *
     * @throws GitHubRequestFailedException
     */
    public function count(GitHubCredentials $credentials, string $query, string $permission = 'Pull requests'): int
    {
        $data = $this->get($credentials, '/search/issues', ['q' => 'repo:'.$credentials->repository.' '.$query, 'per_page' => 1], $permission);

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
        return $this->mergedPullRequests($credentials, $from, $to)['total'];
    }

    /**
     * Pull requests merged in the range, and the median hours from opened to merged.
     *
     * ponytail: the median is of the first 100 the search lists, so one call answers both;
     * read more pages if a repository merges more than 100 in 30 days.
     *
     * @return array{total: int, lead_time_hours: float|null}
     *
     * @throws GitHubRequestFailedException
     */
    public function mergedPullRequests(GitHubCredentials $credentials, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $data = $this->get($credentials, '/search/issues', ['q' => 'repo:'.$credentials->repository.' is:pr is:merged merged:'.self::range($from, $to), 'per_page' => self::PER_PAGE], 'Pull requests');
        $hours = [];

        foreach (self::listOf($data['items'] ?? null) as $pull) {
            $merged = is_array($pull['pull_request'] ?? null) && is_string($pull['pull_request']['merged_at'] ?? null) ? $pull['pull_request']['merged_at'] : ($pull['closed_at'] ?? null);

            if (is_string($pull['created_at'] ?? null) && is_string($merged)) {
                $hours[] = (CarbonImmutable::parse($merged)->getTimestamp() - CarbonImmutable::parse($pull['created_at'])->getTimestamp()) / 3600;
            }
        }

        sort($hours);
        $middle = intdiv(count($hours), 2);
        $median = match (true) {
            $hours === [] => null,
            count($hours) % 2 === 1 => $hours[$middle],
            default => ($hours[$middle - 1] + $hours[$middle]) / 2,
        };

        return [
            'total' => is_int($data['total_count'] ?? null) ? $data['total_count'] : count($hours),
            'lead_time_hours' => $median === null ? null : round($median, 1),
        ];
    }

    /**
     * Issues opened, or closed, in the range; pull requests not counted.
     *
     * @param  'created'|'closed'  $event
     *
     * @throws GitHubRequestFailedException
     */
    public function issues(GitHubCredentials $credentials, string $event, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->count($credentials, 'is:issue '.$event.':'.self::range($from, $to), GitHubPermission::Issues->label());
    }

    /** @throws GitHubRequestFailedException */
    public function openBugs(GitHubCredentials $credentials): int
    {
        return $this->count($credentials, 'is:issue is:open label:bug', GitHubPermission::Issues->label());
    }

    /**
     * The open alerts of one kind, up to MAX_PAGES pages. A repository with the feature off
     * says so, as does a token without the permission.
     *
     * @return list<array<mixed>>
     *
     * @throws GitHubRequestFailedException
     */
    public function openAlerts(GitHubCredentials $credentials, GitHubPermission $kind): array
    {
        return $this->pages($credentials, '/repos/'.$credentials->repository.$kind->path(), ['state' => 'open', 'per_page' => self::PER_PAGE], $kind->label(), maybeOff: true);
    }

    /**
     * Open Dependabot alerts by severity, the worst first, each severity there.
     *
     * @param  list<array<mixed>>  $alerts
     * @return array<string, int>
     */
    public static function bySeverity(array $alerts): array
    {
        $counts = array_fill_keys(self::SEVERITIES, 0);

        foreach ($alerts as $alert) {
            $severity = $alert['security_vulnerability']['severity'] ?? $alert['security_advisory']['severity'] ?? null;

            if (is_string($severity) && isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        return $counts;
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
     * Commits on a branch whose committer date falls in the range, the newest first, up to
     * MAX_PAGES pages. The author is the GitHub
     * account, or else the name (or email) written in the commit.
     *
     * @return list<array{sha: string, message: string, author: string, url: string, committed_at: string}>
     *
     * @throws GitHubRequestFailedException
     */
    public function commits(GitHubCredentials $credentials, string $branch, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $commits = [];

        foreach ($this->pages($credentials, '/repos/'.$credentials->repository.'/commits', [...self::commitRange($branch, $from, $to), 'per_page' => self::PER_PAGE], 'Contents') as $commit) {
            $written = is_array($commit['commit'] ?? null) ? $commit['commit'] : [];
            $author = is_array($written['author'] ?? null) ? $written['author'] : [];
            $committedAt = is_array($written['committer'] ?? null) && is_string($written['committer']['date'] ?? null) ? $written['committer']['date'] : null;

            if ($committedAt === null) {
                continue;
            }

            $commits[] = [
                'sha' => self::text($commit['sha'] ?? null),
                'message' => trim(explode("\n", self::text($written['message'] ?? null), 2)[0]),
                'author' => match (true) {
                    is_array($commit['author'] ?? null) && self::text($commit['author']['login'] ?? null) !== '' => self::text($commit['author']['login']),
                    self::text($author['name'] ?? null) !== '' => self::text($author['name']),
                    default => self::text($author['email'] ?? null),
                },
                'url' => self::text($commit['html_url'] ?? null),
                'committed_at' => $committedAt,
            ];
        }

        return $commits;
    }

    /**
     * The items of a list, page by page as GitHub links them, up to MAX_PAGES pages.
     *
     * @param  array<string, int|string>  $query
     * @return list<array<mixed>>
     *
     * @throws GitHubRequestFailedException
     */
    private function pages(GitHubCredentials $credentials, string $path, array $query, string $permission, bool $maybeOff = false): array
    {
        $items = [];

        for ($page = 0; $path !== null && $page < self::MAX_PAGES; $page++) {
            $response = $this->send($credentials, $path, $query, $permission, $maybeOff);
            $items = [...$items, ...self::listOf($response->json())];
            // The next page's address carries the query along.
            $path = self::link($response, 'next');
            $query = [];
        }

        return $items;
    }

    /**
     * How many commits on a branch fall in the range, in one call: one commit a page, so the
     * last page's number is the count. Held to the ceiling commits() reads, so a range
     * compares with one counted that way.
     *
     * @throws GitHubRequestFailedException
     */
    public function commitCount(GitHubCredentials $credentials, string $branch, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $response = $this->send($credentials, '/repos/'.$credentials->repository.'/commits', [...self::commitRange($branch, $from, $to), 'per_page' => 1], 'Contents');
        $last = self::link($response, 'last');
        parse_str((string) parse_url((string) $last, PHP_URL_QUERY), $query);
        $count = $last === null ? count(self::listOf($response->json())) : (int) ($query['page'] ?? 0);

        return min($count, self::MAX_PAGES * self::PER_PAGE);
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
        $data = $this->send($credentials, $path, $query, $permission)->json();

        return is_array($data) ? $data : throw new GitHubRequestFailedException(__('connectors.github.errors.status', ['status' => 200]));
    }

    /**
     * A 200 from GitHub, or why there is none.
     *
     * @param  array<string, int|string>  $query
     * @param  string|null  $permission  the token permission the call needs, named when it is refused
     * @param  bool  $maybeOff  whether a 403 or 404 may be a feature the repository has turned off
     *
     * @throws GitHubRequestFailedException
     */
    private function send(GitHubCredentials $credentials, string $path, array $query = [], ?string $permission = null, bool $maybeOff = false): Response
    {
        try {
            // A query, even an empty one, replaces the one a next page's address carries.
            $response = $query === [] ? $this->http($credentials)->get($path) : $this->http($credentials)->get($path, $query);
        } catch (ConnectionException $e) {
            throw new GitHubRequestFailedException(__('connectors.github.errors.unreachable'), previous: $e);
        }

        $status = $response->status();

        if ($status === 200) {
            return $response;
        }

        // A rate limit is a 403 or 429 that says so in its headers.
        if ($status === 429 || ($status === 403 && ($response->header('x-ratelimit-remaining') === '0' || $response->header('retry-after') !== ''))) {
            throw new GitHubRequestFailedException(__('connectors.github.errors.rate_limited'));
        }

        // GitHub hides what a token may not read: a missing permission is a 403 or a 404,
        // and a search the token cannot run is a 422.
        throw new GitHubRequestFailedException(match (true) {
            $status === 401 => __('connectors.github.errors.unauthorized'),
            in_array($status, [403, 404], true) && $maybeOff => __('connectors.github.errors.unavailable', ['permission' => $permission]),
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
     * The commits list's filter: a branch, and committer dates in UTC to the second.
     *
     * @return array{sha: string, since: string, until: string}
     */
    private static function commitRange(string $branch, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['sha' => $branch, 'since' => $from->utc()->format('Y-m-d\TH:i:s\Z'), 'until' => $to->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    /**
     * The address of a page the Link header names, such as rel="next"; only one on GitHub's
     * API, so the token goes nowhere else.
     */
    private static function link(Response $response, string $rel): ?string
    {
        return preg_match('/<([^>]+)>;\s*rel="'.$rel.'"/', $response->header('Link'), $match) === 1 && str_starts_with($match[1], self::API.'/') ? $match[1] : null;
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
