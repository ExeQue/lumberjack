<?php

declare(strict_types=1);

namespace Tests\Support\Connectors;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stands GitHub's REST API in for the repository acme/shop: four open pull requests (one a
 * draft, one waiting for review), seven open issues counting them, three merged and two
 * failed runs in any range, a failed run last on main, and a release behind a draft.
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
                '/repos/acme/shop' => Http::response(['full_name' => 'acme/shop', 'default_branch' => 'main', 'open_issues_count' => 7, 'private' => true]),
                '/search/issues' => Http::response(['total_count' => match (true) {
                    str_contains($q, 'is:merged') => 3,
                    str_contains($q, 'review:none') => 1,
                    default => 4,
                }, 'items' => []]),
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
                    ['tag_name' => 'v1.2.0', 'name' => '', 'draft' => false, 'prerelease' => false, 'html_url' => 'https://github.com/acme/shop/releases/tag/v1.2.0', 'published_at' => '2026-11-09T10:00:00Z'],
                ]),
                '/repos/acme/shop/tags' => Http::response([['name' => 'v0.1.0']]),
                default => Http::response(['message' => 'Not Found'], 404),
            };
        });
    }
}
