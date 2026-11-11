<?php

declare(strict_types=1);

use App\Domains\Connectors\GitHub\Actions\BuildGitHubCatalogAction;
use App\Domains\Connectors\GitHub\Actions\PullGitHubSourceAction;
use App\Domains\Connectors\GitHub\Enums\GitHubSource;
use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Actions\SyncDataSourceConnectionAction;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Tests\Support\Connectors\FakesGitHub;

covers(BuildGitHubCatalogAction::class, PullGitHubSourceAction::class, GitHubSource::class);

uses(FakesGitHub::class);

beforeEach(function () {
    $this->travelTo('2026-11-10 12:00:00');
    $this->fakeGitHub();
    $this->connection = DataSourceConnection::factory()->github()->create();
    app(SyncDataSourceConnectionAction::class)->execute($this->connection->id);
    $this->pull = function (string $key, string $period = '7d'): DataSourceWindow {
        $window = DataSourceWindow::query()->create(['data_source_id' => DataSource::query()->where('key', $key)->sole()->id, 'period' => $period, 'bucket' => 86400, 'requested_at' => now()]);
        app(FetchDataSourceWindowAction::class)->execute($window->id);

        return $window->refresh();
    };
});

describe('listing a repository\'s sources', function () {
    it('offers its pull requests, runs, issues, CI and latest release', function () {
        expect($this->connection->refresh()->last_error)->toBeNull()
            ->and(DataSource::query()->pluck('name', 'key')->all())->toMatchArray([
                'open_pull_requests' => 'Open pull requests',
                'waiting_for_review' => 'Pull requests waiting for review',
                'merged' => 'Pull requests merged',
                'failed_runs' => 'Failed workflow runs',
                'open_issues' => 'Open issues',
                'ci_status' => 'CI on the default branch',
                'latest_release' => 'Latest release',
            ]);
    });

    it('shows on the connection when GitHub refuses the token', function () {
        $this->gitHubAnswers = ['/repos/acme/shop' => [['message' => 'Bad credentials'], 401]];
        $connection = DataSourceConnection::factory()->github()->create();

        app(SyncDataSourceConnectionAction::class)->execute($connection->id);

        expect($connection->refresh()->last_error)->toBe(__('connectors.github.errors.unauthorized'));
    });
});

describe('pulling a source for a screen', function () {
    it('answers each source in its shape', function (string $key, mixed $data) {
        $window = ($this->pull)($key);

        expect($window->error)->toBeNull()->and($window->data)->toBe($data);
    })->with([
        'open pull requests' => ['open_pull_requests', 4],
        'waiting for review' => ['waiting_for_review', 1],
        'merged in the period' => ['merged', 3],
        'failed runs in the period' => ['failed_runs', 2],
        'open issues' => ['open_issues', 3],
        'the default branch\'s latest run' => ['ci_status', [['label' => 'CI (main)', 'value' => 'Failed']]],
        'the latest release' => ['latest_release', [['label' => 'v1.2.0', 'value' => '2026-11-09']]],
    ]);

    it('says on the source what the token cannot read', function () {
        $this->gitHubAnswers = ['/repos/acme/shop/actions/runs' => [['message' => 'Resource not accessible by personal access token'], 403]];

        expect(($this->pull)('failed_runs')->error)->toBe(__('connectors.github.errors.permission', ['permission' => 'Actions']));
    });
});
