<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Repositories\Actions\CreateRepoIssueAction;
use App\Domains\Repositories\Exceptions\PublicRepositoryNotAllowedException;
use App\Domains\Repositories\Models\ProjectRepository;
use Illuminate\Support\Facades\Http;

covers(CreateRepoIssueAction::class);

beforeEach(function () {
    $this->issue = Issue::factory()->create();
    ProjectRepository::factory()->for($this->issue->project)->create(['repository' => 'acme/shop']);
});

describe('creating a repository issue', function () {
    it('creates it once and returns the same URL afterwards', function () {
        Http::fake([
            'api.github.com/repos/acme/shop' => Http::response(['private' => true]),
            'api.github.com/repos/acme/shop/issues' => Http::response(['html_url' => 'https://github.com/acme/shop/issues/7'], 201),
        ]);

        $action = app(CreateRepoIssueAction::class);

        expect($action->execute($this->issue))->toBe('https://github.com/acme/shop/issues/7')
            ->and($action->execute($this->issue->refresh()))->toBe('https://github.com/acme/shop/issues/7');

        Http::assertSentCount(2);
    });

    it('refuses a public repository unless allowed', function () {
        Http::fake(['api.github.com/repos/acme/shop' => Http::response(['private' => false])]);

        app(CreateRepoIssueAction::class)->execute($this->issue);
    })->throws(PublicRepositoryNotAllowedException::class);
});
