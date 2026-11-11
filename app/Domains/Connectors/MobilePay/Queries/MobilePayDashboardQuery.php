<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Queries;

use App\Domains\Connectors\MobilePay\Enums\MobilePaySource;
use App\Domains\Connectors\MobilePay\Exceptions\MobilePayRequestFailedException;
use App\Domains\Connectors\MobilePay\Support\MobilePayClient;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * What the Vipps MobilePay page shows: a ledger's figures over the period against the
 * period before, and its revenue per day. The client keeps each day's figures, so a
 * reloaded page only asks for the days still open.
 */
class MobilePayDashboardQuery
{
    public function __construct(
        private readonly MobilePayClient $client,
    ) {
    }

    /**
     * Revenue, transactions, refunds, fees and payouts over the period's ledger dates (today
     * included) and as many dates before, amounts in whole units of the ledger's currency.
     *
     * @return array{currency: string, current: array{revenue: float, transactions: int, refunds: float, fees: float, payouts: float}, previous: array{revenue: float, transactions: int, refunds: float, fees: float, payouts: float}}
     *
     * @throws MobilePayRequestFailedException
     * @throws DecryptException when the keys were saved under another APP_KEY
     */
    public function summary(DataSourceConnection $connection, Period $period): array
    {
        $config = $connection->mobilePayConfig();
        $days = self::days($period);
        $today = CarbonImmutable::now($connection->timezone)->startOfDay();
        $kept = $this->client->days($config->credentials, $config->ledgerId, $today->subDays(2 * $days - 1), $today);
        $current = array_slice($kept, $days, null, true);
        $previous = array_slice($kept, 0, $days, true);

        return [
            'currency' => $config->currency,
            'current' => MobilePaySource::figures(MobilePayClient::totals($current)),
            'previous' => MobilePaySource::figures(MobilePayClient::totals($previous)),
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
                'revenue' => MobilePaySource::figures($figures)['revenue'],
                'transactions' => $figures['transactions'],
            ];
        }

        return $series;
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
