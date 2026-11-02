<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Providers;

use App\Domains\Repositories\Contracts\RepositoryDriver;
use App\Domains\Repositories\Exceptions\RepositoryRequestFailedException;
use App\Domains\Repositories\Models\ProjectRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Override;
use Throwable;

class GitHubDriver implements RepositoryDriver
{
    public const string API = 'https://api.github.com';

    #[Override]
    public function verify(ProjectRepository $repository): bool
    {
        $response = $this->http($repository)->get('/repos/'.$repository->repository);

        if ($response->failed()) {
            throw RepositoryRequestFailedException::status($repository->repository, $response->status());
        }

        return $response->json('private') === false;
    }

    #[Override]
    public function createIssue(ProjectRepository $repository, string $title, string $body, array $labels): string
    {
        // Creating an issue is not idempotent on GitHub's side, so it is not retried.
        $response = Http::baseUrl(self::API)
            ->withToken($repository->token)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(15)
            ->post('/repos/'.$repository->repository.'/issues', array_filter([
                'title' => mb_substr($title, 0, 250),
                'body' => mb_substr($body, 0, 60_000),
                'labels' => $labels === [] ? null : $labels,
            ]));

        if ($response->failed()) {
            throw RepositoryRequestFailedException::status($repository->repository, $response->status());
        }

        return (string) $response->json('html_url');
    }

    private function http(ProjectRepository $repository): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->withToken($repository->token)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(15)
            ->retry(times: 3, sleepMilliseconds: 200, when: fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false);
    }
}
