<?php

declare(strict_types=1);

use App\Domains\Connectors\MobilePay\Actions\BuildMobilePayCatalogAction;
use App\Domains\Connectors\MobilePay\Actions\PullMobilePaySourceAction;
use App\Domains\Connectors\MobilePay\Enums\MobilePaySource;
use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Actions\SyncDataSourceConnectionAction;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Tests\Support\Connectors\FakesMobilePay;

covers(BuildMobilePayCatalogAction::class, PullMobilePaySourceAction::class, MobilePaySource::class);

uses(FakesMobilePay::class);

beforeEach(function () {
    $this->travelTo('2026-11-10 12:00:00');
    $this->fakeMobilePay();
    $this->connection = DataSourceConnection::factory()->mobilePay()->create(['timezone' => 'UTC']);
    app(SyncDataSourceConnectionAction::class)->execute($this->connection->id);
    $this->pull = function (string $key, string $period = '7d'): DataSourceWindow {
        $window = DataSourceWindow::query()->create(['data_source_id' => DataSource::query()->where('key', $key)->sole()->id, 'period' => $period, 'bucket' => 86400, 'requested_at' => now()]);
        app(FetchDataSourceWindowAction::class)->execute($window->id);

        return $window->refresh();
    };
});

describe('listing a ledger\'s sources', function () {
    it('offers its figures, amounts named with the ledger\'s currency', function () {
        expect($this->connection->refresh()->last_error)->toBeNull()
            ->and(DataSource::query()->pluck('name', 'key')->all())->toMatchArray([
                'revenue' => 'Revenue (NOK)',
                'transactions' => 'Transactions',
                'refunds' => 'Refunds (NOK)',
                'fees' => 'Fees (NOK)',
                'payouts' => 'Payouts (NOK)',
                'revenue.series' => 'Revenue per day (NOK)',
            ]);
    });

    it('shows on the connection when Vipps MobilePay refuses the keys', function () {
        $this->mobilePayAnswers['/settlement/v1/ledgers'] = [['title' => 'Unauthorized'], 401];
        $connection = DataSourceConnection::factory()->mobilePay()->create();

        app(SyncDataSourceConnectionAction::class)->execute($connection->id);

        expect($connection->refresh()->last_error)->toBe(__('connectors.mobilepay.errors.unauthorized'));
    });
});

describe('pulling a source for a screen', function () {
    it('reads the period as whole ledger dates, today included, amounts in whole units', function (string $key, string $period, int|float $value) {
        expect(($this->pull)($key, $period)->data)->toEqual($value);
    })->with([
        'a week\'s revenue' => ['revenue', '7d', 849.0],
        'a week\'s transactions' => ['transactions', '7d', 4],
        'a week\'s refunds' => ['refunds', '7d', 100.0],
        'a week\'s fees' => ['fees', '7d', 15.0],
        'a week\'s payouts' => ['payouts', '7d', 484.0],
        'today\'s revenue so far' => ['revenue', '24h', 250.0],
        'a month\'s revenue' => ['revenue', '30d', 1149.0],
    ]);

    it('has a point for every day of the period, days without sales at 0', function () {
        $data = ($this->pull)('revenue.series')->data;

        expect($data)->toHaveCount(7)
            ->and(collect($data)->firstWhere('at', strtotime('2026-11-09 00:00:00 UTC'))['value'])->toEqual(599)
            ->and(collect($data)->sum('value'))->toEqual(849);
    });
});
