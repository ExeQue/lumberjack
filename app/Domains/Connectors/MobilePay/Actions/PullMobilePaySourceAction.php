<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Actions;

use App\Domains\Connectors\MobilePay\Enums\MobilePaySource;
use App\Domains\Connectors\MobilePay\Exceptions\MobilePayRequestFailedException;
use App\Domains\Connectors\MobilePay\Support\MobilePayClient;
use App\Domains\Connectors\MobilePay\Support\MobilePayDays;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSource;
use Carbon\CarbonImmutable;

/**
 * One of a Vipps MobilePay ledger's sources over a range, as an app would answer for it.
 */
class PullMobilePaySourceAction
{
    /** Rows a list source answers, but for the hours and weekdays, which list all of theirs. */
    public const int LIST_ROWS = 10;

    public function __construct(
        private readonly MobilePayClient $client,
    ) {
    }

    /**
     * The ledger is booked per day, so a range is read as as many ledger dates, today
     * included: a day, a week or a month. A series has a point per day whatever the bucket.
     * The balance is as it is now, whatever the range.
     *
     * @return array{shape: string, data: mixed}
     *
     * @throws MobilePayRequestFailedException
     */
    public function execute(DataSource $source, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $connection = $source->dataSourceConnection ?? throw new MobilePayRequestFailedException(__('connectors.mobilepay.errors.unknown_source'));
        $kind = MobilePaySource::tryFrom((string) $source->key) ?? throw new MobilePayRequestFailedException(__('connectors.mobilepay.errors.unknown_source'));
        $config = $connection->mobilePayConfig();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        // The balance is now's whatever the range: the latest entry of the last week's days.
        $count = $kind === MobilePaySource::Balance ? MobilePayDays::BALANCE_DAYS : max(1, (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400));
        $days = $this->client->days($config->credentials, $config->ledgerId, $today->subDays($count - 1), $today);

        $local = fn (int $at): string => CarbonImmutable::createFromTimestamp($at, $timezone)->format('Y-m-d H:i');
        $entry = fn (array $entry, string $label): array => ['label' => $label.($entry['reference'] === '' ? '' : ' ('.$entry['reference'].')'), 'value' => MobilePaySource::major($entry['amount'])];

        $list = match ($kind) {
            MobilePaySource::LatestTransactions => array_map(fn (array $e): array => $entry($e, $local($e['at']).' '.(string) __('connectors.mobilepay.types.'.$e['type'])), MobilePayDays::latest($days, self::LIST_ROWS)),
            MobilePaySource::LargestSales => array_map(fn (array $e): array => $entry($e, $local($e['at'])), MobilePayDays::largest($days, self::LIST_ROWS)),
            MobilePaySource::PayoutList => array_map(fn (array $payout): array => ['label' => $payout['date'], 'value' => MobilePaySource::major($payout['amount'])], MobilePayDays::payouts($days, self::LIST_ROWS)),
            MobilePaySource::SalesByHour => collect(MobilePayDays::byHour($days, $timezone))->map(fn (array $sales, int $hour): array => ['label' => sprintf('%02d:00', $hour), 'value' => $sales['sales']])->values()->all(),
            MobilePaySource::SalesByWeekday => collect(MobilePayDays::byWeekday($days, $timezone))->map(fn (array $sales, int $weekday): array => ['label' => (string) __('connectors.mobilepay.weekdays.'.$weekday), 'value' => $sales['sales']])->values()->all(),
            MobilePaySource::RevenueByUnit => collect(MobilePayDays::units($days))->take(self::LIST_ROWS)->map(fn (int $revenue, int|string $unit): array => ['label' => (string) $unit, 'value' => MobilePaySource::major($revenue)])->values()->all(),
            default => null,
        };

        if ($list !== null) {
            return ['shape' => DataSourceShape::List->value, 'data' => $list];
        }

        if ($kind === MobilePaySource::Balance) {
            $balance = MobilePayDays::balance($days);

            return ['shape' => DataSourceShape::Number->value, 'data' => $balance === null ? null : MobilePaySource::major($balance)];
        }

        if ($kind->shape() === DataSourceShape::Number) {
            return ['shape' => DataSourceShape::Number->value, 'data' => $kind->figure(MobilePayDays::totals($days))];
        }

        $points = [];

        foreach ($days as $date => $figures) {
            $points[] = ['at' => CarbonImmutable::parse($date, $timezone)->getTimestamp(), 'value' => $kind->figure($figures['figures'])];
        }

        return ['shape' => DataSourceShape::Series->value, 'data' => $points];
    }
}
