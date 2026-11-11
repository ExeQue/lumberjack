<?php

declare(strict_types=1);

namespace Tests\Support\Connectors;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stands GitHub's REST API in for the repository acme/shop: four open pull requests (one a
 * draft, one waiting for review), seven open issues counting them, three merged (in 2, 4 and
 * 10 hours) and two failed runs in any range, a failed run last on main, and a release
 * (with notes) behind a draft. 42 stars, 5 forks, 3 watchers. Any range has three commits on main over
 * two pages (ana on 10 and 7 November, Bo without an account on 9 November), and 17 when
 * only counted; six issues opened, two closed, one open bug. Dependabot has three open
 * alerts (critical, high, high), code scanning is not set up, secret scanning has none.
 */
trait FakesGitHub
{
    /**
     * Paths answered otherwise, as a body, a status and headers: a permission the token
     * lacks, a repository without releases.
     *
     * @var array<string, array{0: mixed, 1?: int, 2?: array<string, string>}>
     */
    public array $gitHubAnswers = [];

    public function fakeGitHub(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $q = is_string($query['q'] ?? null) ? $query['q'] : '';

            if (isset($this->gitHubAnswers[$path])) {
                return Http::response(...$this->gitHubAnswers[$path]);
            }

            return match ($path) {
                '/repos/acme/shop' => Http::response(['full_name' => 'acme/shop', 'default_branch' => 'main', 'open_issues_count' => 7, 'private' => true, 'stargazers_count' => 42, 'forks_count' => 5, 'subscribers_count' => 3]),
                '/search/issues' => Http::response(['total_count' => match (true) {
                    str_contains($q, 'is:merged') => 3,
                    str_contains($q, 'review:none') => 1,
                    str_contains($q, 'label:bug') => 1,
                    str_contains($q, 'is:issue created:') => 6,
                    str_contains($q, 'is:issue closed:') => 2,
                    default => 4,
                }, 'items' => str_contains($q, 'is:merged') ? [
                    ['created_at' => '2026-11-09T08:00:00Z', 'closed_at' => '2026-11-09T10:00:00Z', 'pull_request' => ['merged_at' => '2026-11-09T10:00:00Z']],
                    ['created_at' => '2026-11-08T08:00:00Z', 'closed_at' => '2026-11-08T12:00:00Z', 'pull_request' => ['merged_at' => '2026-11-08T12:00:00Z']],
                    ['created_at' => '2026-11-07T08:00:00Z', 'closed_at' => '2026-11-07T18:00:00Z', 'pull_request' => ['merged_at' => '2026-11-07T18:00:00Z']],
                ] : []]),
                '/repos/acme/shop/commits' => match (true) {
                    ($query['per_page'] ?? null) === '1' => Http::response([self::commit('a1', 'ana', 'Ana', '2026-11-10T09:00:00Z')], 200, ['Link' => '<https://api.github.com/repos/acme/shop/commits?per_page=1&page=2>; rel="next", <https://api.github.com/repos/acme/shop/commits?per_page=1&page=17>; rel="last"']),
                    ($query['page'] ?? null) === '2' => Http::response([self::commit('c3', 'ana', 'Ana', '2026-11-07T10:00:00Z')], 200, ['Link' => '<https://api.github.com/repos/acme/shop/commits?per_page=100&page=1>; rel="prev"']),
                    default => Http::response([
                        self::commit('a1', 'ana', 'Ana', '2026-11-10T09:00:00Z', "Add a cart\n\nWith a button."),
                        self::commit('b2', null, 'Bo', '2026-11-09T15:00:00Z'),
                    ], 200, ['Link' => '<https://api.github.com/repos/acme/shop/commits?per_page=100&page=2>; rel="next", <https://api.github.com/repos/acme/shop/commits?per_page=100&page=2>; rel="last"']),
                },
                '/repos/acme/shop/issues' => Http::response([]),
                '/repos/acme/shop/dependabot/alerts' => Http::response([
                    ['number' => 3, 'security_advisory' => ['severity' => 'critical'], 'security_vulnerability' => ['severity' => 'critical']],
                    ['number' => 2, 'security_advisory' => ['severity' => 'high'], 'security_vulnerability' => ['severity' => 'high']],
                    ['number' => 1, 'security_advisory' => ['severity' => 'high']],
                ]),
                '/repos/acme/shop/code-scanning/alerts' => Http::response(['message' => 'no analysis found'], 404),
                '/repos/acme/shop/secret-scanning/alerts' => Http::response([]),
                '/repos/acme/shop/pulls' => Http::response([
                    ['number' => 12, 'title' => 'Add a cart', 'user' => ['login' => 'ana'], 'draft' => false, 'html_url' => 'https://github.com/acme/shop/pull/12', 'created_at' => '2026-11-10T09:00:00Z'],
                    ['number' => 11, 'title' => 'Try a theme', 'user' => ['login' => 'bo'], 'draft' => true, 'html_url' => 'https://github.com/acme/shop/pull/11', 'created_at' => '2026-11-08T09:00:00Z'],
                ]),
                '/repos/acme/shop/actions/runs' => Http::response(($query['status'] ?? null) === 'failure'
                    ? ['total_count' => 2, 'workflow_runs' => []]
                    : ['total_count' => 1, 'workflow_runs' => [
                        ['name' => 'CI', 'display_title' => 'Add a cart', 'head_branch' => 'main', 'event' => 'push', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/shop/actions/runs/1', 'created_at' => '2026-11-10T10:00:00Z'],
                    ]]),
                '/repos/acme/shop/releases' => Http::response([
                    ['tag_name' => 'v2.0.0', 'name' => 'Next', 'draft' => true, 'prerelease' => false, 'html_url' => 'https://github.com/acme/shop/releases/tag/untagged', 'published_at' => null],
                    ['tag_name' => 'v1.2.0', 'name' => '', 'draft' => false, 'prerelease' => false, 'html_url' => 'https://github.com/acme/shop/releases/tag/v1.2.0', 'published_at' => '2026-11-09T10:00:00Z', 'body' => "## Fixed\n\n- The cart <script>keeps</script> its items.\n"],
                ]),
                '/repos/acme/shop/tags' => Http::response([['name' => 'v0.1.0']]),
                default => Http::response(['message' => 'Not Found'], 404),
            };
        });
    }

    /**
     * A commit as GitHub lists it; without a login, the author has no GitHub account.
     *
     * @return array<string, mixed>
     */
    public static function commit(string $sha, ?string $login, string $name, string $date, ?string $message = null): array
    {
        return [
            'sha' => $sha,
            'html_url' => 'https://github.com/acme/shop/commit/'.$sha,
            'author' => $login === null ? null : ['login' => $login],
            'commit' => [
                'message' => $message ?? 'Commit '.$sha,
                'author' => ['name' => $name, 'email' => strtolower($name).'@example.com', 'date' => $date],
                'committer' => ['name' => 'GitHub', 'email' => 'noreply@github.com', 'date' => $date],
            ],
        ];
    }
}
