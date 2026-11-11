<?php

declare(strict_types=1);

use App\Domains\Connectors\GitHub\Data\GitHubCredentials;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use Carbon\CarbonImmutable;
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
        expect($this->client->defaultBranchRun($this->credentials))->toMatchArray(['name' => 'CI', 'branch' => 'main', 'conclusion' => 'failure', 'result' => 'failure']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/actions/runs?branch=main&exclude_pull_requests=true&per_page=1'));
    });

    it('reads the open pull requests', function () {
        expect($this->client->pullRequests($this->credentials))->toBe([
            ['number' => 12, 'title' => 'Add a cart', 'author' => 'ana', 'draft' => false, 'url' => 'https://github.com/acme/shop/pull/12', 'created_at' => '2026-11-10T09:00:00Z'],
            ['number' => 11, 'title' => 'Try a theme', 'author' => 'bo', 'draft' => true, 'url' => 'https://github.com/acme/shop/pull/11', 'created_at' => '2026-11-08T09:00:00Z'],
        ]);
    });

    it('reads the latest release, leaving drafts out and naming one without a name by its tag', function () {
        expect($this->client->latestRelease($this->credentials))->toBe(['name' => 'v1.2.0', 'tag' => 'v1.2.0', 'prerelease' => false, 'url' => 'https://github.com/acme/shop/releases/tag/v1.2.0', 'published_at' => '2026-11-09T10:00:00Z']);
    });

    it('falls back to the first tag when there is no release', function () {
        $this->gitHubAnswers = ['/repos/acme/shop/releases' => [[]]];

        expect($this->client->latestRelease($this->credentials))->toMatchArray(['name' => 'v0.1.0', 'published_at' => null]);
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
