<?php

declare(strict_types=1);

use App\Domains\Connectors\GitHub\Actions\BuildGitHubCatalogAction;
use App\Domains\Connectors\GitHub\Actions\PullGitHubSourceAction;
use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\GitHub\Enums\GitHubPermission;
use App\Domains\Connectors\GitHub\Enums\GitHubSource;
use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Actions\SyncDataSourceConnectionAction;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Illuminate\Support\Facades\Http;
use Tests\Support\Connectors\FakesGitHub;

covers(BuildGitHubCatalogAction::class, PullGitHubSourceAction::class, GitHubSource::class);

uses(FakesGitHub::class);

beforeEach(function () {
    $this->travelTo('2026-11-10 12:00:00');
    $this->fakeGitHub();
    $this->connection = DataSourceConnection::factory()->github()->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', GitHubPermission::cases())]);
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

    it('offers every source when the token has the optional permissions', function () {
        expect(DataSource::query()->pluck('key')->sort()->values()->all())->toBe(collect(GitHubSource::cases())->map->value->sort()->values()->all());
    });

    it('leaves out the sources of an optional permission the token lacks', function () {
        $connection = DataSourceConnection::factory()->github()->create(['config' => new GitHubConfig('acme/shop', 'github_pat_test', [GitHubPermission::SecretScanningAlerts])]);

        app(SyncDataSourceConnectionAction::class)->execute($connection->id);

        $keys = DataSource::query()->where('data_source_connection_id', $connection->id)->pluck('key')->all();
        expect($keys)->toContain('secret_scanning_alerts', 'commits', 'stars')
            ->not->toContain('issues_opened', 'open_bugs', 'dependabot_alerts', 'dependabot_by_severity', 'code_scanning_alerts');
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
        'commits in the period' => ['commits', 3],
        'distinct authors, by login or else name' => ['authors', 2],
        'the latest commits' => ['latest_commits', [
            ['label' => 'Add a cart (ana)', 'value' => '2026-11-10 09:00'],
            ['label' => 'Commit b2 (Bo)', 'value' => '2026-11-09 15:00'],
            ['label' => 'Commit c3 (ana)', 'value' => '2026-11-07 10:00'],
        ]],
        'the top contributors' => ['top_contributors', [['label' => 'ana', 'value' => 2], ['label' => 'Bo', 'value' => 1]]],
        'the newest open pull requests, drafts marked' => ['pull_request_list', [
            ['label' => '#12 Add a cart (ana)', 'value' => '2026-11-10'],
            ['label' => '#11 Try a theme (bo, draft)', 'value' => '2026-11-08'],
        ]],
        'the median lead time in hours' => ['lead_time', 4],
        'stars' => ['stars', 42],
        'forks' => ['forks', 5],
        'watchers' => ['watchers', 3],
        'issues opened' => ['issues_opened', 6],
        'issues closed' => ['issues_closed', 2],
        'open bugs' => ['open_bugs', 1],
        'open Dependabot alerts' => ['dependabot_alerts', 3],
        'Dependabot alerts by severity' => ['dependabot_by_severity', [
            ['label' => 'Critical', 'value' => 1],
            ['label' => 'High', 'value' => 2],
            ['label' => 'Medium', 'value' => 0],
            ['label' => 'Low', 'value' => 0],
        ]],
        'open secret scanning alerts' => ['secret_scanning_alerts', 0],
    ]);

    it('counts commits per day of the range, days without any at 0', function () {
        $window = ($this->pull)('commits.series');

        expect($window->error)->toBeNull()
            ->and(array_column($window->data, 'value', 'at'))->toBe([
                strtotime('2026-11-03 00:00:00 UTC') => 0,
                strtotime('2026-11-04 00:00:00 UTC') => 0,
                strtotime('2026-11-05 00:00:00 UTC') => 0,
                strtotime('2026-11-06 00:00:00 UTC') => 0,
                strtotime('2026-11-07 00:00:00 UTC') => 1,
                strtotime('2026-11-08 00:00:00 UTC') => 0,
                strtotime('2026-11-09 00:00:00 UTC') => 1,
                strtotime('2026-11-10 00:00:00 UTC') => 1,
            ]);
    });

    it('reads the commits once for the sources of one range', function () {
        ($this->pull)('commits');
        ($this->pull)('authors');
        ($this->pull)('top_contributors');

        // Two pages of commits, once.
        expect(collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/commits'))->count())->toBe(2);
    });

    it('says on a security source that the feature is off', function () {
        expect(($this->pull)('code_scanning_alerts')->error)->toContain(__('connectors.github.errors.unavailable', ['permission' => 'Code scanning alerts']));
    });

    it('says on the source what the token cannot read', function () {
        $this->gitHubAnswers = ['/repos/acme/shop/actions/runs' => [['message' => 'Resource not accessible by personal access token'], 403]];

        expect(($this->pull)('failed_runs')->error)->toBe(__('connectors.github.errors.permission', ['permission' => 'Actions']));
    });
});
