<?php

declare(strict_types=1);

use App\Domains\Connectors\GitHub\Data\GitHubCredentials;
use App\Domains\Connectors\GitHub\Enums\GitHubPermission;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Connectors\FakesGitHub;

covers(GitHubClient::class);

uses(FakesGitHub::class);

beforeEach(function () {
    $this->fakeGitHub();
    $this->client = app(GitHubClient::class);
    $this->credentials = new GitHubCredentials('acme/shop', 'github_pat_test');
});

describe('asking GitHub', function () {
    it('sends the token and the API version', function () {
        $this->client->repository($this->credentials);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/shop'
            && $request->header('Authorization')[0] === 'Bearer github_pat_test'
            && $request->header('X-GitHub-Api-Version')[0] === '2022-11-28');
    });

    it('counts pull requests through the search API, within the repository', function () {
        expect($this->client->openPullRequests($this->credentials))->toBe(4)
            ->and($this->client->waitingForReview($this->credentials))->toBe(1)
            ->and($this->client->merged($this->credentials, CarbonImmutable::parse('2026-11-03 12:00:00'), CarbonImmutable::parse('2026-11-10 12:00:00')))->toBe(3);

        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'q=repo:acme/shop is:pr is:open draft:false review:none'));
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'merged:2026-11-03T12:00:00Z..2026-11-10T12:00:00Z'));
    });

    it('counts open issues without the pull requests GitHub counts among them', function () {
        expect($this->client->openIssues($this->credentials))->toBe(3);
    });

    it('counts failed runs created in the range', function () {
        expect($this->client->failedRuns($this->credentials, CarbonImmutable::parse('2026-11-03 12:00:00'), CarbonImmutable::parse('2026-11-10 12:00:00')))->toBe(2);

        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'status=failure&created=2026-11-03T12:00:00Z..2026-11-10T12:00:00Z'));
    });

    it('reads the latest run on the default branch', function () {
        expect($this->client->branchRun($this->credentials))->toMatchArray(['name' => 'CI', 'branch' => 'main', 'conclusion' => 'failure', 'result' => 'failure']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/actions/runs?branch=main&exclude_pull_requests=true&per_page=1'));
    });

    it('reads the open pull requests', function () {
        expect($this->client->pullRequests($this->credentials))->toBe([
            ['number' => 12, 'title' => 'Add a cart', 'author' => 'ana', 'draft' => false, 'url' => 'https://github.com/acme/shop/pull/12', 'created_at' => '2026-11-10T09:00:00Z'],
            ['number' => 11, 'title' => 'Try a theme', 'author' => 'bo', 'draft' => true, 'url' => 'https://github.com/acme/shop/pull/11', 'created_at' => '2026-11-08T09:00:00Z'],
        ]);
    });

    it('reads the latest release, leaving drafts out and naming one without a name by its tag', function () {
        expect($this->client->latestRelease($this->credentials))->toBe(['name' => 'v1.2.0', 'tag' => 'v1.2.0', 'prerelease' => false, 'url' => 'https://github.com/acme/shop/releases/tag/v1.2.0', 'published_at' => '2026-11-09T10:00:00Z', 'body' => "## Fixed\n\n- The cart <script>keeps</script> its items."]);
    });

    it('falls back to the first tag when there is no release', function () {
        $this->gitHubAnswers = ['/repos/acme/shop/releases' => [[]]];

        expect($this->client->latestRelease($this->credentials))->toMatchArray(['name' => 'v0.1.0', 'published_at' => null]);
    });
});

describe('reading commits', function () {
    beforeEach(function () {
        $this->from = CarbonImmutable::parse('2026-11-03 12:00:00');
        $this->to = CarbonImmutable::parse('2026-11-10 12:00:00');
    });

    it('reads the range\'s commits on the branch page by page as the Link header says', function () {
        $commits = $this->client->commits($this->credentials, 'main', $this->from, $this->to);

        expect($commits)->toHaveCount(3)
            ->and(array_column($commits, 'author'))->toBe(['ana', 'Bo', 'ana'])
            ->and($commits[0])->toMatchArray(['sha' => 'a1', 'message' => 'Add a cart', 'committed_at' => '2026-11-10T09:00:00Z']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), '/commits?sha=main&since=2026-11-03T12:00:00Z&until=2026-11-10T12:00:00Z&per_page=100'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/shop/commits?per_page=100&page=2');
    });

    it('stops at the page ceiling', function () {
        $this->gitHubAnswers = ['/repos/acme/shop/commits' => [[FakesGitHub::commit('x', 'ana', 'Ana', '2026-11-10T09:00:00Z')], 200, ['Link' => '<https://api.github.com/repos/acme/shop/commits?page=2>; rel="next"']]];

        expect($this->client->commits($this->credentials, 'main', $this->from, $this->to))->toHaveCount(GitHubClient::MAX_PAGES);

        Http::assertSentCount(GitHubClient::MAX_PAGES);
    });

    it('follows no link away from GitHub\'s API', function () {
        $this->gitHubAnswers = ['/repos/acme/shop/commits' => [[FakesGitHub::commit('x', 'ana', 'Ana', '2026-11-10T09:00:00Z')], 200, ['Link' => '<https://evil.example/commits?page=2>; rel="next"']]];

        expect($this->client->commits($this->credentials, 'main', $this->from, $this->to))->toHaveCount(1);

        Http::assertSentCount(1);
    });

    it('counts a range\'s commits from the last page\'s number, held to the ceiling', function (string $link, int $count) {
        $this->gitHubAnswers = ['/repos/acme/shop/commits' => [[FakesGitHub::commit('x', 'ana', 'Ana', '2026-11-10T09:00:00Z')], 200, $link === '' ? [] : ['Link' => $link]]];

        expect($this->client->commitCount($this->credentials, 'main', $this->from, $this->to))->toBe($count);
    })->with([
        'many pages' => ['<https://api.github.com/repos/acme/shop/commits?per_page=1&page=17>; rel="last"', 17],
        'past the ceiling' => ['<https://api.github.com/repos/acme/shop/commits?per_page=1&page=5000>; rel="last"', 1000],
        'one page' => ['', 1],
    ]);
});

describe('pull requests, issues and the repository', function () {
    it('takes the median lead time from the merged pull requests', function () {
        expect($this->client->mergedPullRequests($this->credentials, CarbonImmutable::parse('2026-11-03 12:00:00'), CarbonImmutable::parse('2026-11-10 12:00:00')))
            ->toBe(['total' => 3, 'lead_time_hours' => 4.0]);
    });

    it('counts issues opened, closed and open bugs', function () {
        $from = CarbonImmutable::parse('2026-11-03 12:00:00');
        $to = CarbonImmutable::parse('2026-11-10 12:00:00');

        expect($this->client->issues($this->credentials, 'created', $from, $to))->toBe(6)
            ->and($this->client->issues($this->credentials, 'closed', $from, $to))->toBe(2)
            ->and($this->client->openBugs($this->credentials))->toBe(1);

        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'q=repo:acme/shop is:issue closed:2026-11-03T12:00:00Z..2026-11-10T12:00:00Z'));
    });

    it('reads stars, forks and watchers', function () {
        expect($this->client->repository($this->credentials))->toMatchArray(['stars' => 42, 'forks' => 5, 'watchers' => 3]);
    });
});

describe('security alerts and optional permissions', function () {
    it('reads the open Dependabot alerts by severity', function () {
        $alerts = $this->client->openAlerts($this->credentials, GitHubPermission::DependabotAlerts);

        expect($alerts)->toHaveCount(3)
            ->and(GitHubClient::bySeverity($alerts))->toBe(['critical' => 1, 'high' => 2, 'medium' => 0, 'low' => 0]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/dependabot/alerts?state=open&per_page=100'));
    });

    it('says a feature is off rather than the repository unreadable', function () {
        expect(fn () => $this->client->openAlerts($this->credentials, GitHubPermission::CodeScanningAlerts))
            ->toThrow(GitHubRequestFailedException::class, __('connectors.github.errors.unavailable', ['permission' => 'Code scanning alerts']));
    });

    it('finds the optional permissions the token has, a feature turned off counting as had', function () {
        expect($this->client->grantedPermissions($this->credentials))->toBe(GitHubPermission::cases());
    });

    it('leaves out a permission the token lacks', function () {
        $this->gitHubAnswers = ['/repos/acme/shop/issues' => [['message' => 'Resource not accessible by personal access token'], 403]];

        expect($this->client->grantedPermissions($this->credentials))->not->toContain(GitHubPermission::Issues)->toHaveCount(3);
    });
});

describe('what GitHub refuses', function () {
    it('names the permission the token lacks', function (string $path, string $permission) {
        $this->gitHubAnswers = [$path => [['message' => 'Resource not accessible by personal access token'], 403]];

        expect(fn () => $this->client->verify($this->credentials))->toThrow(GitHubRequestFailedException::class, __('connectors.github.errors.permission', ['permission' => $permission]));
    })->with([
        'pull requests' => ['/repos/acme/shop/pulls', 'Pull requests'],
        'actions' => ['/repos/acme/shop/actions/runs', 'Actions'],
        'contents' => ['/repos/acme/shop/releases', 'Contents'],
    ]);

    it('says why the repository cannot be read', function (int $status, string $message) {
        $this->gitHubAnswers = ['/repos/acme/shop' => [['message' => 'Not Found'], $status]];

        expect(fn () => $this->client->verify($this->credentials))->toThrow(GitHubRequestFailedException::class, $message);
    })->with([
        'a token refused' => [401, 'GitHub refused the token'],
        'a repository it cannot see' => [404, 'The token cannot read acme/shop'],
        'anything else' => [500, 'GitHub answered with HTTP 500'],
    ]);

    it('says when GitHub asks to slow down', function () {
        $this->gitHubAnswers = ['/repos/acme/shop' => [['message' => 'API rate limit exceeded'], 403, ['x-ratelimit-remaining' => '0']]];

        $this->client->repository($this->credentials);
    })->throws(GitHubRequestFailedException::class, 'GitHub asks to slow down');
});

describe('asking several calls at once', function () {
    it('asks a call whose connection failed again on its own, keeping the others\' answers', function () {
        $tries = 0;
        Http::swap(new Factory());
        Http::fake(function (Request $request) use (&$tries) {
            return match ((string) parse_url($request->url(), PHP_URL_PATH)) {
                '/repos/acme/shop' => ++$tries === 1 ? Http::failedConnection() : Http::response(['full_name' => 'acme/shop', 'default_branch' => 'main']),
                default => Http::response(['total_count' => 4, 'items' => []]),
            };
        });

        $this->client->prefetch($this->credentials, GitHubClient::summaryCalls($this->credentials, CarbonImmutable::parse('2026-10-27 12:00:00'), CarbonImmutable::parse('2026-11-03 12:00:00'), CarbonImmutable::parse('2026-11-10 12:00:00')));

        expect($this->client->repository($this->credentials)['full_name'])->toBe('acme/shop')
            ->and($this->client->openPullRequests($this->credentials))->toBe(4)
            ->and($tries)->toBe(2);
    });
});
