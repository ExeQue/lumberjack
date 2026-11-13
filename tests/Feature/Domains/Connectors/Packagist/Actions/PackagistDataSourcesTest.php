<?php

declare(strict_types=1);

use App\Domains\Connectors\Packagist\Actions\BuildPackagistCatalogAction;
use App\Domains\Connectors\Packagist\Actions\PullPackagistSourceAction;
use App\Domains\Connectors\Packagist\Enums\PackagistSource;
use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Actions\SyncDataSourceConnectionAction;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Tests\Support\Connectors\FakesPackagist;

covers(BuildPackagistCatalogAction::class, PullPackagistSourceAction::class, PackagistSource::class);

uses(FakesPackagist::class);

beforeEach(function () {
    $this->travelTo('2026-11-13 12:00:00');
    $this->fakePackagist();
    $this->connection = DataSourceConnection::factory()->packagist()->create();
    app(SyncDataSourceConnectionAction::class)->execute($this->connection->id);
    $this->pull = function (string $key, string $period = '7d'): DataSourceWindow {
        $window = DataSourceWindow::query()->create(['data_source_id' => DataSource::query()->where('key', $key)->sole()->id, 'period' => $period, 'bucket' => 86400, 'requested_at' => now()]);
        app(FetchDataSourceWindowAction::class)->execute($window->id);

        return $window->refresh();
    };
});

describe('listing a package\'s sources', function () {
    it('offers its downloads, popularity and advisories', function () {
        expect($this->connection->refresh()->last_error)->toBeNull()
            ->and(DataSource::query()->pluck('name', 'key')->all())->toMatchArray(['downloads' => 'Downloads', 'downloads.series' => 'Downloads per day', 'advisories' => 'Security advisories']);
    });

    it('shows on the connection when the package is gone', function () {
        $connection = DataSourceConnection::factory()->packagist('acme/gone')->create();

        app(SyncDataSourceConnectionAction::class)->execute($connection->id);

        expect($connection->refresh()->last_error)->toBe(__('connectors.packagist.errors.not_found'));
    });
});

describe('pulling a source for a screen', function () {
    it('reads the period as the last days Packagist published', function (string $key, string $period, int $expected) {
        expect(($this->pull)($key, $period)->data)->toBe($expected);
    })->with([
        'downloads over a week' => ['downloads', '7d', 90],
        'downloads over a day' => ['downloads', '24h', 20],
        'downloads in all' => ['total_downloads', '7d', 12345],
        'favers' => ['favers', '7d', 12],
        'advisories' => ['advisories', '7d', 1],
    ]);

    it('has a point for each day', function () {
        $data = ($this->pull)('downloads.series')->data;

        expect($data)->toHaveCount(7)
            ->and(end($data))->toBe(['at' => strtotime('2026-11-12 00:00:00 UTC'), 'value' => 20]);
    });
});
