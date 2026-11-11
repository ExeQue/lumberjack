<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Support;

use App\Domains\Connectors\GitHub\Data\GitHubCredentials;
use App\Domains\Connectors\GitHub\Enums\GitHubPermission;
use App\Domains\Connectors\GitHub\Exceptions\GitHubBranchNotFoundException;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
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

    /** The most rows a list on the page loads with Load more: one page of GitHub's. */
    public const int MAX_ROWS = self::PER_PAGE;

    /** The most characters of a release's notes kept. */
    public const int MAX_NOTES = 20_000;

    /**
     * Pages read for one list, such as a range's commits or the open alerts.
     *
     * ponytail: 10 pages, so a list counts at most 1000 commits or alerts; read more pages,
     * or count elsewhere, if a repository commits more than that in 30 days.
     */
    public const int MAX_PAGES = 10;

    /**
     * Pages of branches read, for the branch field's suggestions.
     *
     * ponytail: 3 pages, so at most 300 branches are suggested; a name past them is still
     * typed and checked on save. Search GitHub as the name is typed if that falls short.
     */
    public const int BRANCH_PAGES = 3;

    /** The severities a Dependabot alert has, the worst first. */
    public const array SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /** Releases read for the latest, so a few drafts before it do not hide it. */
    private const int LATEST_RELEASES = 5;

    /**
     * Answers asked together by prefetch(), by call, each read once by the call that asks
     * for it next. Never more than one prefetch's calls, so it cannot grow.
     *
     * @var array<string, Response>
     */
    private array $prefetched = [];

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
        $data = $this->get($credentials, ...self::repositoryCall($credentials));

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
        $path = '/repos/'.$credentials->repository;
        // Asked at once, and read in this order, so the first refusal is the one named.
        $this->prefetch($credentials, [self::repositoryCall($credentials), [$path.'/pulls', ['per_page' => 1]], [$path.'/actions/runs', ['per_page' => 1]], [$path.'/releases', ['per_page' => 1]]]);
        $repository = $this->repository($credentials);

        $this->get($credentials, $path.'/pulls', ['per_page' => 1], 'Pull requests');
        $this->get($credentials, $path.'/actions/runs', ['per_page' => 1], 'Actions');
        $this->get($credentials, $path.'/releases', ['per_page' => 1], 'Contents');

        return $repository;
    }

    /**
     * Whether the repository has the branch; reading it needs Contents (or Metadata) only.
     *
     * @throws GitHubBranchNotFoundException
     * @throws GitHubRequestFailedException
     */
    public function verifyBranch(GitHubCredentials $credentials, string $branch): void
    {
        // A branch's slashes stay path separators, as GitHub reads them.
        $path = '/repos/'.$credentials->repository.'/branches/'.implode('/', array_map(rawurlencode(...), explode('/', $branch)));
        $this->send($credentials, $path, [], 'Contents', notFound: new GitHubBranchNotFoundException(__('connectors.github.errors.branch_not_found', ['branch' => $branch, 'repository' => $credentials->repository])));
    }

    /**
     * The branch commits and CI are read from: the one chosen, or else the default branch,
     * asked of GitHub.
     *
     * @throws GitHubRequestFailedException
     */
    public function branch(GitHubCredentials $credentials): string
    {
        return $credentials->branch ?? $this->repository($credentials)['default_branch'];
    }

    /**
     * The repository's branch names, as GitHub lists them, up to BRANCH_PAGES pages.
     *
     * @return list<string>
     *
     * @throws GitHubRequestFailedException
     */
    public function branches(GitHubCredentials $credentials): array
    {
        return array_values(collect($this->pages($credentials, '/repos/'.$credentials->repository.'/branches', ['per_page' => self::PER_PAGE], 'Contents', maxPages: self::BRANCH_PAGES))
            ->map(fn (array $branch): string => self::text($branch['name'] ?? null))
            ->filter()
            ->all());
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
        $this->prefetch($credentials, array_map(fn (GitHubPermission $permission): array => ['/repos/'.$credentials->repository.$permission->path(), ['per_page' => 1]], GitHubPermission::cases()));

        foreach (GitHubPermission::cases() as $permission) {
            $response = $this->fetch($credentials, '/repos/'.$credentials->repository.$permission->path(), ['per_page' => 1]);

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
        $data = $this->get($credentials, ...[...self::searchCall($credentials, $query), $permission]);

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
        $data = $this->get($credentials, ...[...self::searchCall($credentials, self::mergedQuery($from, $to), self::PER_PAGE), 'Pull requests']);
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
        return $this->count($credentials, self::issuesQuery($event, $from, $to), GitHubPermission::Issues->label());
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
        return $this->pages($credentials, ...[...self::alertsCall($credentials, $kind), $kind->label(), true]);
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

    /**
     * Workflow runs failed in the range: on the chosen branch, or on every branch when none
     * is chosen.
     *
     * @throws GitHubRequestFailedException
     */
    public function failedRuns(GitHubCredentials $credentials, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->runs($credentials, self::failedFilters($credentials, $from, $to), 1)['total'];
    }

    /**
     * The latest workflow run on the branch read (the chosen one, or the default branch),
     * from a push or otherwise; the default branch is asked of GitHub unless given.
     *
     * @return array{name: string, title: string, branch: string, event: string, status: string, conclusion: string|null, result: string, url: string, created_at: string|null}|null
     *
     * @throws GitHubRequestFailedException
     */
    public function branchRun(GitHubCredentials $credentials, ?string $branch = null): ?array
    {
        $branch ??= $this->branch($credentials);

        return $this->runs($credentials, self::branchRunFilters($branch), 1)['runs'][0] ?? null;
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
        $data = $this->get($credentials, ...[...self::runsCall($credentials, $filters, $rows), 'Actions']);
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
    public function pullRequests(GitHubCredentials $credentials, int $rows = self::ROWS): array
    {
        $data = $this->get($credentials, '/repos/'.$credentials->repository.'/pulls', ['state' => 'open', 'per_page' => $rows], 'Pull requests');

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
     * Published releases, the newest first, with their notes as written (Markdown). Drafts
     * are left out; a read-only token does not see them anyway.
     *
     * @return list<array{name: string, tag: string, prerelease: bool, url: string, published_at: string|null, body: string}>
     *
     * @throws GitHubRequestFailedException
     */
    public function releases(GitHubCredentials $credentials, int $rows = self::ROWS): array
    {
        $data = $this->get($credentials, ...[...self::releasesCall($credentials, $rows), 'Contents']);
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
                'body' => mb_substr(trim(self::text($release['body'] ?? null)), 0, self::MAX_NOTES),
            ];
        }

        return $releases;
    }

    /**
     * The newest release, or else the first tag GitHub lists, which has no date.
     *
     * @return array{name: string, tag: string, prerelease: bool, url: string, published_at: string|null, body: string}|null
     *
     * @throws GitHubRequestFailedException
     */
    public function latestRelease(GitHubCredentials $credentials): ?array
    {
        $release = $this->releases($credentials, self::LATEST_RELEASES)[0] ?? null;

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
            'body' => '',
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

        foreach ($this->pages($credentials, ...[...self::commitsCall($credentials, $branch, $from, $to, self::PER_PAGE), 'Contents']) as $commit) {
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
     * The items of a list, page by page as GitHub links them, up to MAX_PAGES pages or as many as asked.
     *
     * @param  array<string, int|string>  $query
     * @return list<array<mixed>>
     *
     * @throws GitHubRequestFailedException
     */
    private function pages(GitHubCredentials $credentials, string $path, array $query, string $permission, bool $maybeOff = false, int $maxPages = self::MAX_PAGES): array
    {
        $items = [];

        for ($page = 0; $path !== null && $page < $maxPages; $page++) {
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
        $response = $this->send($credentials, ...[...self::commitsCall($credentials, $branch, $from, $to, 1), 'Contents']);
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
     * @param  GitHubBranchNotFoundException|null  $notFound  what a 404 means, when it is not a permission the token lacks
     *
     * @throws GitHubBranchNotFoundException
     * @throws GitHubRequestFailedException
     */
    private function send(GitHubCredentials $credentials, string $path, array $query = [], ?string $permission = null, bool $maybeOff = false, ?GitHubBranchNotFoundException $notFound = null): Response
    {
        $response = $this->fetch($credentials, $path, $query);
        $status = $response->status();

        if ($status === 200) {
            return $response;
        }

        // A rate limit is a 403 or 429 that says so in its headers.
        if ($status === 429 || ($status === 403 && ($response->header('x-ratelimit-remaining') === '0' || $response->header('retry-after') !== ''))) {
            throw new GitHubRequestFailedException(__('connectors.github.errors.rate_limited'));
        }

        if ($status === 404 && $notFound !== null) {
            throw $notFound;
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

    /**
     * Asks GitHub for several calls at once (each a path and its query), so the methods
     * that make them next read the answers rather than asking one after another. Each
     * answer is then read, and refused, by its own call as if asked alone; one the
     * connection failed for is asked again then, with the retries. Pages after the first
     * still follow one another.
     *
     * @param  list<array{0: string, 1: array<string, int|string>}>  $calls
     */
    public function prefetch(GitHubCredentials $credentials, array $calls): void
    {
        $this->prefetched = [];
        $responses = Http::pool(function (Pool $pool) use ($credentials, $calls): void {
            foreach ($calls as [$path, $query]) {
                // Pooled requests do not retry; a failed one is asked again by fetch().
                self::configure($pool->as(self::callKey($credentials, $path, $query)), $credentials)->get($path, $query);
            }
        });

        foreach ($responses as $key => $response) {
            if ($response instanceof Response) {
                $this->prefetched[(string) $key] = $response;
            }
        }
    }

    /**
     * The calls the summary makes that do not wait on one another: the repository, the
     * search counts, the failed runs, the releases, and the branch's latest run when the
     * branch is known without asking.
     *
     * @return list<array{0: string, 1: array<string, int|string>}>
     */
    public static function summaryCalls(GitHubCredentials $credentials, CarbonImmutable $before, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            self::repositoryCall($credentials),
            self::searchCall($credentials, 'is:pr is:open'),
            self::searchCall($credentials, 'is:pr is:open draft:false review:none'),
            self::searchCall($credentials, self::mergedQuery($from, $to), self::PER_PAGE),
            self::searchCall($credentials, self::mergedQuery($before, $from), self::PER_PAGE),
            self::runsCall($credentials, self::failedFilters($credentials, $from, $to), 1),
            self::runsCall($credentials, self::failedFilters($credentials, $before, $from), 1),
            self::releasesCall($credentials, self::LATEST_RELEASES),
            ...($credentials->branch === null ? [] : [self::runsCall($credentials, self::branchRunFilters($credentials->branch), 1)]),
        ];
    }

    /**
     * The issue counts: opened and closed in the range and the one before, and the open bugs.
     *
     * @return list<array{0: string, 1: array<string, int|string>}>
     */
    public static function issueCalls(GitHubCredentials $credentials, CarbonImmutable $before, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            self::searchCall($credentials, self::issuesQuery('created', $from, $to)),
            self::searchCall($credentials, self::issuesQuery('created', $before, $from)),
            self::searchCall($credentials, self::issuesQuery('closed', $from, $to)),
            self::searchCall($credentials, self::issuesQuery('closed', $before, $from)),
            self::searchCall($credentials, 'is:issue is:open label:bug'),
        ];
    }

    /**
     * The first page of the range's commits on a branch, and the count of the range before.
     *
     * @return list<array{0: string, 1: array<string, int|string>}>
     */
    public static function commitCalls(GitHubCredentials $credentials, string $branch, CarbonImmutable $before, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [self::commitsCall($credentials, $branch, $from, $to, self::PER_PAGE), self::commitsCall($credentials, $branch, $before, $from, 1)];
    }

    /**
     * The first page of each kind's open alerts.
     *
     * @param  array<GitHubPermission>  $kinds
     * @return list<array{0: string, 1: array<string, int|string>}>
     */
    public static function alertCalls(GitHubCredentials $credentials, array $kinds): array
    {
        return array_values(array_map(fn (GitHubPermission $kind): array => self::alertsCall($credentials, $kind), $kinds));
    }

    /**
     * GitHub's answer to a call: the one prefetched, or else asked now.
     *
     * @param  array<string, int|string>  $query
     *
     * @throws GitHubRequestFailedException when GitHub cannot be reached
     */
    private function fetch(GitHubCredentials $credentials, string $path, array $query): Response
    {
        $key = self::callKey($credentials, $path, $query);

        if (isset($this->prefetched[$key])) {
            $response = $this->prefetched[$key];
            unset($this->prefetched[$key]);

            return $response;
        }

        try {
            // A query, even an empty one, replaces the one a next page's address carries.
            return $query === [] ? $this->http($credentials)->get($path) : $this->http($credentials)->get($path, $query);
        } catch (ConnectionException $e) {
            throw new GitHubRequestFailedException(__('connectors.github.errors.unreachable'), previous: $e);
        }
    }

    private function http(GitHubCredentials $credentials): PendingRequest
    {
        return self::configure(Http::retry(
            times: 3,
            sleepMilliseconds: 200,
            when: fn (?Throwable $e): bool => $e instanceof ConnectionException,
            throw: false,
        ), $credentials);
    }

    /** What every call to GitHub carries, pooled or not. */
    private static function configure(PendingRequest $request, GitHubCredentials $credentials): PendingRequest
    {
        return $request->baseUrl(self::API)->withToken($credentials->token)->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent('Lumberjack-github/1')
            ->timeout(self::TIMEOUT_S)->connectTimeout(5);
    }

    /**
     * A call as prefetch() keeps its answer: the token's, so another connection's answer is
     * never read.
     *
     * @param  array<string, int|string>  $query
     */
    private static function callKey(GitHubCredentials $credentials, string $path, array $query): string
    {
        return hash('xxh128', $credentials->token."\n".$path."\n".http_build_query($query));
    }

    /** @return array{0: string, 1: array<string, int|string>} */
    private static function repositoryCall(GitHubCredentials $credentials): array
    {
        return ['/repos/'.$credentials->repository, []];
    }

    /** @return array{0: string, 1: array<string, int|string>} */
    private static function searchCall(GitHubCredentials $credentials, string $query, int $perPage = 1): array
    {
        return ['/search/issues', ['q' => 'repo:'.$credentials->repository.' '.$query, 'per_page' => $perPage]];
    }

    /**
     * @param  array<string, string>  $filters
     * @return array{0: string, 1: array<string, int|string>}
     */
    private static function runsCall(GitHubCredentials $credentials, array $filters, int $rows): array
    {
        return ['/repos/'.$credentials->repository.'/actions/runs', [...$filters, 'per_page' => $rows]];
    }

    /** @return array{0: string, 1: array<string, int|string>} */
    private static function releasesCall(GitHubCredentials $credentials, int $rows): array
    {
        return ['/repos/'.$credentials->repository.'/releases', ['per_page' => $rows]];
    }

    /** @return array{0: string, 1: array<string, int|string>} */
    private static function commitsCall(GitHubCredentials $credentials, string $branch, CarbonImmutable $from, CarbonImmutable $to, int $perPage): array
    {
        return ['/repos/'.$credentials->repository.'/commits', [...self::commitRange($branch, $from, $to), 'per_page' => $perPage]];
    }

    /** @return array{0: string, 1: array<string, int|string>} */
    private static function alertsCall(GitHubCredentials $credentials, GitHubPermission $kind): array
    {
        return ['/repos/'.$credentials->repository.$kind->path(), ['state' => 'open', 'per_page' => self::PER_PAGE]];
    }

    /**
     * Failed runs in the range: on the chosen branch, or on every branch when none is chosen.
     *
     * @return array<string, string>
     */
    private static function failedFilters(GitHubCredentials $credentials, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['status' => 'failure', 'created' => self::range($from, $to), ...($credentials->branch === null ? [] : ['branch' => $credentials->branch])];
    }

    /** @return array<string, string> */
    private static function branchRunFilters(string $branch): array
    {
        return ['branch' => $branch, 'exclude_pull_requests' => 'true'];
    }

    private static function mergedQuery(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return 'is:pr is:merged merged:'.self::range($from, $to);
    }

    /** @param  'created'|'closed'  $event */
    private static function issuesQuery(string $event, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return 'is:issue '.$event.':'.self::range($from, $to);
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
