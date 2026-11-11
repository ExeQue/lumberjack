<?php

declare(strict_types=1);

use App\Domains\Connectors\MobilePay\Actions\BuildMobilePayCatalogAction;
use App\Domains\Connectors\MobilePay\Actions\PullMobilePaySourceAction;
use App\Domains\Connectors\MobilePay\Enums\MobilePaySource;
use App\Domains\Connectors\MobilePay\Support\MobilePayDays;
use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Actions\SyncDataSourceConnectionAction;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Illuminate\Support\Collection;
use Tests\Support\Connectors\FakesMobilePay;

covers(BuildMobilePayCatalogAction::class, PullMobilePaySourceAction::class, MobilePaySource::class, MobilePayDays::class);

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
                'average_order' => 'Average order value (NOK)',
                'refund_rate' => 'Refund rate (%)',
                'fee_rate' => 'Fee rate (%)',
                'balance' => 'Balance to be paid out (NOK)',
                'latest_transactions' => 'Latest transactions (NOK)',
                'largest_sales' => 'Largest sales (NOK)',
                'payout_list' => 'Latest payouts (NOK)',
                'sales_by_hour' => 'Sales by hour of day',
                'sales_by_weekday' => 'Sales by weekday',
                'revenue_by_unit' => 'Revenue per sales unit (NOK)',
            ]);
    });

    it('offers every source it has', function () {
        expect(DataSource::query()->pluck('key')->all())->toEqualCanonicalizing(array_map(fn (MobilePaySource $source): string => $source->value, MobilePaySource::cases()));
    });

    it('shows on the connection when Vipps MobilePay refuses the keys', function () {
        $this->mobilePayAnswers['/settlement/v1/ledgers'] = [['title' => 'Unauthorized'], 401];
        $connection = DataSourceConnection::factory()->mobilePay()->create();

        app(SyncDataSourceConnectionAction::class)->execute($connection->id);

        expect($connection->refresh()->last_error)->toBe(__('connectors.mobilepay.errors.unauthorized'));
    });
});

describe('pulling a source for a screen', function () {
    it('reads the period as whole ledger dates, today included, amounts in whole units', function (string $key, string $period, int|float|null $value) {
        expect(($this->pull)($key, $period)->data)->toEqual($value);
    })->with([
        'a week\'s revenue' => ['revenue', '7d', 849.0],
        'a week\'s transactions' => ['transactions', '7d', 4],
        'a week\'s refunds' => ['refunds', '7d', 100.0],
        'a week\'s fees' => ['fees', '7d', 15.0],
        'a week\'s payouts' => ['payouts', '7d', 484.0],
        'today\'s revenue so far' => ['revenue', '24h', 250.0],
        'a month\'s revenue' => ['revenue', '30d', 1149.0],
        'a week\'s average order' => ['average_order', '7d', 212.25],
        'a week\'s refund rate' => ['refund_rate', '7d', 11.8],
        'a week\'s fee rate' => ['fee_rate', '7d', 1.8],
        'today\'s average order so far' => ['average_order', '24h', 125.0],
        'the balance, whatever the period' => ['balance', '24h', 250.0],
    ]);

    it('has no average or rate without sales', function (string $key) {
        $this->travelTo('2026-11-20 12:00:00');

        expect(($this->pull)($key, '24h')->data)->toBeNull();
    })->with(['average_order', 'refund_rate', 'fee_rate']);

    it('lists the period\'s transactions, largest sales, payouts and revenue per sales unit', function (string $key, array $rows) {
        expect(($this->pull)($key)->data)->toEqual($rows);
    })->with([
        'the latest transactions' => ['latest_transactions', [
            ['label' => '2026-11-10 11:00 Sale (order-d)', 'value' => 50.0],
            ['label' => '2026-11-10 08:00 Sale (order-c)', 'value' => 200.0],
            ['label' => '2026-11-09 19:00 Refund (order-b)', 'value' => -100.0],
            ['label' => '2026-11-09 18:30 Sale (order-b)', 'value' => 100.0],
            ['label' => '2026-11-09 09:15 Sale (acme-shop-123-order123abc)', 'value' => 499.0],
        ]],
        'the largest sales' => ['largest_sales', [
            ['label' => '2026-11-09 09:15 (acme-shop-123-order123abc)', 'value' => 499.0],
            ['label' => '2026-11-10 08:00 (order-c)', 'value' => 200.0],
            ['label' => '2026-11-09 18:30 (order-b)', 'value' => 100.0],
            ['label' => '2026-11-10 11:00 (order-d)', 'value' => 50.0],
        ]],
        'the payouts' => ['payout_list', [['label' => '2026-11-09', 'value' => 484.0]]],
        'the revenue per sales unit' => ['revenue_by_unit', [['label' => 'NO:123455', 'value' => 749.0], ['label' => 'NO:123456', 'value' => 100.0]]],
    ]);

    it('counts sales per hour and weekday in the connection\'s time zone', function (string $timezone, array $hours, array $weekdays) {
        $this->connection->update(['timezone' => $timezone]);
        $byHour = collect(($this->pull)('sales_by_hour', '30d')->data);
        $byWeekday = collect(($this->pull)('sales_by_weekday', '30d')->data);
        $sold = fn (Collection $rows): array => $rows->where('value', '>', 0)->pluck('value', 'label')->all();

        expect($byHour)->toHaveCount(24)
            ->and($byWeekday)->toHaveCount(7)
            ->and($sold($byHour))->toBe($hours)
            ->and($sold($byWeekday))->toBe($weekdays);
    })->with([
        'in UTC' => ['UTC', ['08:00' => 1, '09:00' => 1, '11:00' => 1, '18:00' => 1, '23:00' => 1], ['Monday' => 2, 'Tuesday' => 2, 'Sunday' => 1]],
        'in Oslo, an hour ahead' => ['Europe/Oslo', ['00:00' => 1, '09:00' => 1, '10:00' => 1, '12:00' => 1, '19:00' => 1], ['Monday' => 3, 'Tuesday' => 2]],
    ]);

    it('has a point for every day of the period, days without sales at 0', function () {
        $data = ($this->pull)('revenue.series')->data;

        expect($data)->toHaveCount(7)
            ->and(collect($data)->firstWhere('at', strtotime('2026-11-09 00:00:00 UTC'))['value'])->toEqual(599)
            ->and(collect($data)->sum('value'))->toEqual(849);
    });
});
