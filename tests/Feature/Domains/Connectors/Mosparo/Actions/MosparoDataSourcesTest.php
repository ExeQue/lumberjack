<?php

declare(strict_types=1);

use App\Domains\Connectors\Mosparo\Actions\BuildMosparoCatalogAction;
use App\Domains\Connectors\Mosparo\Actions\PullMosparoSourceAction;
use App\Domains\Connectors\Mosparo\Enums\MosparoSource;
use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Actions\SyncDataSourceConnectionAction;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Illuminate\Support\Facades\Http;
use Tests\Support\Health\FakesHealthProbes;

covers(BuildMosparoCatalogAction::class, PullMosparoSourceAction::class, MosparoSource::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    config(['health.data_sources_allow_private' => false]);
    $this->fakeDns();
    $this->travelTo('2026-11-10 12:00:00');
    $this->connection = DataSourceConnection::factory()->mosparo()->create(['timezone' => 'UTC']);
    $this->statistics = ['numberOfValidSubmissions' => 6, 'numberOfSpamSubmissions' => 2, 'numberOfDelayedRequests' => 0, 'numberOfBlockedRequests' => 1, 'numbersByDate' => [
        '2026-11-09' => ['numberOfValidSubmissions' => 4, 'numberOfSpamSubmissions' => 2, 'numberOfBlockedRequests' => 1],
        // A day before the week.
        '2026-11-03' => ['numberOfValidSubmissions' => 5, 'numberOfSpamSubmissions' => 0],
    ]];
    $this->refused = false;
    Http::fake(['mosparo.example.com/*' => fn () => $this->refused ? Http::response(['error' => true], 401) : Http::response(['result' => true, 'data' => $this->statistics])]);
    app(SyncDataSourceConnectionAction::class)->execute($this->connection->id);
    $this->pull = function (string $key, string $period = '7d'): DataSourceWindow {
        $window = DataSourceWindow::query()->create(['data_source_id' => DataSource::query()->where('key', $key)->sole()->id, 'period' => $period, 'bucket' => 86400, 'requested_at' => now()]);
        app(FetchDataSourceWindowAction::class)->execute($window->id);

        return $window->refresh();
    };
});

describe('listing a mosparo project\'s sources', function () {
    it('offers its counts, the spam rate and the counts per day', function () {
        expect($this->connection->refresh()->last_error)->toBeNull()
            ->and(DataSource::query()->pluck('name', 'key')->all())->toMatchArray(['valid' => 'Valid submissions', 'spam_rate' => 'Spam rate (%)', 'spam.series' => 'Spam submissions per day']);
    });

    it('shows on the connection when mosparo refuses the keys', function () {
        $this->refused = true;
        $connection = DataSourceConnection::factory()->mosparo()->create();

        app(SyncDataSourceConnectionAction::class)->execute($connection->id);

        expect($connection->refresh()->last_error)->toBe(__('connectors.mosparo.errors.unauthorized'));
    });
});

describe('pulling a source for a screen', function () {
    it('reads the period as whole days, today included, and makes the spam rate of them', function () {
        expect(($this->pull)('spam_rate')->data)->toBe(33.3)
            ->and(($this->pull)('blocked')->data)->toBe(1)
            ->and(($this->pull)('valid', '30d')->data)->toBe(9)
            ->and(($this->pull)('spam', '24h')->data)->toBe(0);
    });

    it('has a point for every day of the period, those without submissions at 0', function () {
        $data = ($this->pull)('valid.series')->data;

        expect($data)->toHaveCount(7)
            ->and(collect($data)->firstWhere('at', strtotime('2026-11-09 00:00:00 UTC'))['value'])->toBe(4)
            ->and(collect($data)->sum('value'))->toBe(4);
    });
});
