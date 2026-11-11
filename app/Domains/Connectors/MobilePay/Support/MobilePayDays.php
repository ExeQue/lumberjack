<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Support;

use Carbon\CarbonImmutable;

/**
 * Figures and lists over several ledger dates, as the client keeps them, amounts in minor
 * units. Hours and weekdays are read in a time zone.
 *
 * @phpstan-import-type Day from MobilePayClient
 * @phpstan-import-type Entry from MobilePayClient
 * @phpstan-import-type Payout from MobilePayClient
 */
final class MobilePayDays
{
    /**
     * Days read back for the balance as it is now. A day without entries has none, and a
     * payout usually brings it to 0 each day.
     */
    public const int BALANCE_DAYS = 7;

    /**
     * The sum of several days' figures.
     *
     * @param  iterable<Day>  $days
     * @return array{revenue: int, transactions: int, refunds: int, fees: int, payouts: int}
     */
    public static function totals(iterable $days): array
    {
        $figures = collect($days)->pluck('figures');

        return [
            'revenue' => (int) $figures->sum('revenue'),
            'transactions' => (int) $figures->sum('transactions'),
            'refunds' => (int) $figures->sum('refunds'),
            'fees' => (int) $figures->sum('fees'),
            'payouts' => (int) $figures->sum('payouts'),
        ];
    }

    /**
     * The balance after the latest entry of the days, or null when they have none.
     *
     * @param  iterable<Day>  $days
     */
    public static function balance(iterable $days): ?int
    {
        /** @var array{at: int, amount: int}|null $latest */
        $latest = collect($days)->pluck('balance')->filter()->sortBy('at')->last();

        return $latest['amount'] ?? null;
    }

    /**
     * The latest sales and refunds, the newest first.
     *
     * @param  iterable<Day>  $days
     * @return array<int, Entry>
     */
    public static function latest(iterable $days, int $rows): array
    {
        return collect($days)->flatMap(fn (array $day): array => $day['latest'])->sortByDesc('at')->take($rows)->values()->all();
    }

    /**
     * The largest captures, the largest first.
     *
     * @param  iterable<Day>  $days
     * @return array<int, Entry>
     */
    public static function largest(iterable $days, int $rows): array
    {
        return collect($days)->flatMap(fn (array $day): array => $day['largest'])->sortByDesc(fn (array $entry): array => [$entry['amount'], $entry['at']])->take($rows)->values()->all();
    }

    /**
     * The payouts, the newest first, each as paid out (an aborted payout is negative).
     *
     * @param  iterable<Day>  $days
     * @return array<int, Payout>
     */
    public static function payouts(iterable $days, int $rows): array
    {
        return collect($days)->flatMap(fn (array $day): array => $day['payouts'])->sortByDesc('at')->take($rows)->values()->all();
    }

    /**
     * Captures and their sum per hour of the day, 0 to 23 in the time zone.
     *
     * ponytail: the client counts per UTC hour, so a zone off UTC by a half hour moves each sale up to 30 minutes.
     *
     * @param  iterable<Day>  $days
     * @return array<int, array{sales: int, revenue: int}>
     */
    public static function byHour(iterable $days, string $timezone): array
    {
        return self::byLocal($days, $timezone, 'G', range(0, 23));
    }

    /**
     * Captures and their sum per weekday in the time zone, 1 (Monday) to 7 (Sunday).
     *
     * @param  iterable<Day>  $days
     * @return array<int, array{sales: int, revenue: int}>
     */
    public static function byWeekday(iterable $days, string $timezone): array
    {
        return self::byLocal($days, $timezone, 'N', range(1, 7));
    }

    /**
     * The sum of the captures per sales unit, the most first.
     *
     * @param  iterable<Day>  $days
     * @return array<string, int>
     */
    public static function units(iterable $days): array
    {
        $units = [];

        foreach ($days as $day) {
            foreach ($day['units'] as $unit => $revenue) {
                $units[$unit] = ($units[$unit] ?? 0) + $revenue;
            }
        }

        arsort($units);

        return $units;
    }

    /**
     * @param  iterable<Day>  $days
     * @param  list<int>  $slots
     * @return array<int, array{sales: int, revenue: int}>
     */
    private static function byLocal(iterable $days, string $timezone, string $format, array $slots): array
    {
        $counts = [];

        foreach ($slots as $slot) {
            $counts[$slot] = ['sales' => 0, 'revenue' => 0];
        }

        foreach ($days as $day) {
            foreach ($day['hours'] as $hour => $sales) {
                $slot = (int) CarbonImmutable::createFromTimestamp($hour, $timezone)->format($format);
                $counts[$slot] = ['sales' => $counts[$slot]['sales'] + $sales['sales'], 'revenue' => $counts[$slot]['revenue'] + $sales['revenue']];
            }
        }

        return $counts;
    }
}
