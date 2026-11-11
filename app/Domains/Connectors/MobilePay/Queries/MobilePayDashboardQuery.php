<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Queries;

use App\Domains\Connectors\MobilePay\Enums\MobilePaySource;
use App\Domains\Connectors\MobilePay\Exceptions\MobilePayRequestFailedException;
use App\Domains\Connectors\MobilePay\Support\MobilePayClient;
use App\Domains\Connectors\MobilePay\Support\MobilePayDays;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * What the Vipps MobilePay page shows: a ledger's figures over the period against the
 * period before, and its revenue per day. The client keeps each day's figures, so a
 * reloaded page only asks for the days still open.
 *
 * @phpstan-type Figures array{revenue: float, transactions: int, refunds: float, fees: float, payouts: float, average_order: float|null, refund_rate: float|null, fee_rate: float|null}
 */
class MobilePayDashboardQuery
{
    public function __construct(
        private readonly MobilePayClient $client,
    ) {
    }

    /**
     * Revenue, transactions, refunds, fees and payouts, the average order and the refund and
     * fee rates over the period's ledger dates (today included) and as many dates before,
     * amounts in whole units of the ledger's currency; and the balance after the latest entry
     * of the last week, which is what Vipps MobilePay holds for the merchant now.
     *
     * @return array{currency: string, balance: float|null, current: Figures, previous: Figures}
     *
     * @throws MobilePayRequestFailedException
     * @throws DecryptException when the keys were saved under another APP_KEY
     */
    public function summary(DataSourceConnection $connection, Period $period): array
    {
        $config = $connection->mobilePayConfig();
        $days = self::days($period);
        $today = CarbonImmutable::now($connection->timezone)->startOfDay();
        $kept = $this->client->days($config->credentials, $config->ledgerId, $today->subDays(max(2 * $days, MobilePayDays::BALANCE_DAYS) - 1), $today);
        $current = array_slice($kept, -$days, null, true);
        $previous = array_slice($kept, -2 * $days, $days, true);
        $balance = MobilePayDays::balance(array_slice($kept, -MobilePayDays::BALANCE_DAYS));

        return [
            'currency' => $config->currency,
            'balance' => $balance === null ? null : MobilePaySource::major($balance),
            'current' => MobilePaySource::figures(MobilePayDays::totals($current)),
            'previous' => MobilePaySource::figures(MobilePayDays::totals($previous)),
        ];
    }

    /**
     * Each of the period's ledger dates, today included, oldest first.
     *
     * @return list<array{t: string, revenue: float, transactions: int}>
     *
     * @throws MobilePayRequestFailedException
     * @throws DecryptException when the keys were saved under another APP_KEY
     */
    public function series(DataSourceConnection $connection, Period $period): array
    {
        $config = $connection->mobilePayConfig();
        $today = CarbonImmutable::now($connection->timezone)->startOfDay();
        $series = [];

        foreach ($this->client->days($config->credentials, $config->ledgerId, $today->subDays(self::days($period) - 1), $today) as $date => $figures) {
            $series[] = [
                't' => CarbonImmutable::parse($date, $connection->timezone)->toIso8601String(),
                'revenue' => MobilePaySource::major($figures['figures']['revenue']),
                'transactions' => $figures['figures']['transactions'],
            ];
        }

        return $series;
    }

    /**
     * The period's lists and breakdowns, amounts in whole units: the latest sales and refunds,
     * the largest sales and the payouts, each as many as asked for; sales and revenue per
     * hour of the day and per weekday in the connection's time zone; and revenue per sales
     * unit. They come from the days the client keeps, so showing more asks Vipps nothing.
     *
     * @param  array{transactions: int, largest: int, payouts: int}  $rows
     * @return array{latest: array<int, array{at: string, type: string, amount: float, reference: string}>, largest: array<int, array{at: string, amount: float, reference: string}>, payouts: array<int, array{date: string, amount: float}>, hours: array<int, array{hour: int, sales: int, revenue: float}>, weekdays: array<int, array{label: string, sales: int, revenue: float}>, units: array<int, array{unit: string, revenue: float}>}
     *
     * @throws MobilePayRequestFailedException
     * @throws DecryptException when the keys were saved under another APP_KEY
     */
    public function details(DataSourceConnection $connection, Period $period, array $rows): array
    {
        $config = $connection->mobilePayConfig();
        $timezone = $connection->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $days = $this->client->days($config->credentials, $config->ledgerId, $today->subDays(self::days($period) - 1), $today);
        $at = fn (int $at): string => CarbonImmutable::createFromTimestamp($at, $timezone)->toIso8601String();

        return [
            'latest' => array_map(fn (array $entry): array => ['at' => $at($entry['at']), 'type' => $entry['type'], 'amount' => MobilePaySource::major($entry['amount']), 'reference' => $entry['reference']], MobilePayDays::latest($days, $rows['transactions'])),
            'largest' => array_map(fn (array $entry): array => ['at' => $at($entry['at']), 'amount' => MobilePaySource::major($entry['amount']), 'reference' => $entry['reference']], MobilePayDays::largest($days, $rows['largest'])),
            'payouts' => array_map(fn (array $payout): array => ['date' => $payout['date'], 'amount' => MobilePaySource::major($payout['amount'])], MobilePayDays::payouts($days, $rows['payouts'])),
            'hours' => collect(MobilePayDays::byHour($days, $timezone))->map(fn (array $sales, int $hour): array => ['hour' => $hour, 'sales' => $sales['sales'], 'revenue' => MobilePaySource::major($sales['revenue'])])->values()->all(),
            'weekdays' => collect(MobilePayDays::byWeekday($days, $timezone))->map(fn (array $sales, int $weekday): array => ['label' => (string) __('connectors.mobilepay.weekdays.'.$weekday), 'sales' => $sales['sales'], 'revenue' => MobilePaySource::major($sales['revenue'])])->values()->all(),
            'units' => collect(MobilePayDays::units($days))->map(fn (int $revenue, int|string $unit): array => ['unit' => (string) $unit, 'revenue' => MobilePaySource::major($revenue)])->values()->all(),
        ];
    }

    /** How many ledger dates a period covers; the ledger has nothing finer than a day. */
    public static function days(Period $period): int
    {
        return match ($period) {
            Period::Month => 30,
            Period::Week => 7,
            default => 1,
        };
    }
}
