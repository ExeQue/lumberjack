<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Queries;

use App\Domains\Connectors\Packagist\Exceptions\PackagistRequestFailedException;
use App\Domains\Connectors\Packagist\Support\PackagistClient;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\StatisticsCache;
use Carbon\CarbonImmutable;

/**
 * What the Packagist page and its data sources show of a package. Packagist publishes a
 * day's downloads once the day is over, so a period is that many of the last days it has
 * published. Its figures change once a day, so they are kept a quarter of an hour.
 */
class PackagistDashboardQuery
{
    public function __construct(
        private readonly PackagistClient $client,
    ) {
    }

    /**
     * Downloads over the period and the one before it, the last day they cover, and the
     * package's own figures.
     *
     * @return array{downloads: int, previous: int, through: string|null, total: int, monthly: int, favers: int, dependents: int, suggesters: int, github_stars: int|null, advisories: int}
     *
     * @throws PackagistRequestFailedException
     */
    public function summary(DataSourceConnection $connection, Period $period): array
    {
        $package = $this->package($connection);
        $days = $this->days($connection, $period);
        $count = self::count($period);
        $values = array_values($days);

        return [
            'downloads' => array_sum(array_slice($values, -$count)),
            'previous' => array_sum(array_slice($values, -2 * $count, $count)),
            'through' => array_key_last($days),
            'total' => $package['downloads']['total'],
            'monthly' => $package['downloads']['monthly'],
            'favers' => $package['favers'],
            'dependents' => $package['dependents'],
            'suggesters' => $package['suggesters'],
            'github_stars' => $package['github_stars'],
            'advisories' => count($this->advisories($connection)),
        ];
    }

    /**
     * Downloads on each of the period's days, oldest first.
     *
     * @return list<array{t: string, downloads: int}>
     *
     * @throws PackagistRequestFailedException
     */
    public function series(DataSourceConnection $connection, Period $period): array
    {
        $days = array_slice($this->days($connection, $period), -self::count($period), preserve_keys: true);

        return array_map(fn (string $date, int $downloads): array => ['t' => CarbonImmutable::parse($date, 'UTC')->toIso8601String(), 'downloads' => $downloads], array_keys($days), array_values($days));
    }

    /**
     * Downloads of each major version over the period, the newest major first, with its share.
     *
     * @return list<array{major: string, downloads: int, share: float}>
     *
     * @throws PackagistRequestFailedException
     */
    public function majors(DataSourceConnection $connection, Period $period): array
    {
        $count = self::count($period);
        $majors = StatisticsCache::remember('packagist-majors', Period::Month, [$connection->id, $this->name($connection), $count], fn (): array => $this->client->majors($this->name($connection), self::from($count)));
        $sums = array_map(fn (array $days): int => array_sum(array_slice(array_values($days), -$count)), $majors);
        $total = array_sum($sums);

        return array_map(
            fn (string|int $major, int $downloads): array => ['major' => (string) $major, 'downloads' => $downloads, 'share' => $total > 0 ? round($downloads / $total, 4) : 0.0],
            array_keys($sums),
            array_values($sums),
        );
    }

    /**
     * @return list<array{version: string, time: string|null}>
     *
     * @throws PackagistRequestFailedException
     */
    public function releases(DataSourceConnection $connection): array
    {
        return $this->package($connection)['releases'];
    }

    /**
     * @return list<array{id: string, title: string, link: string|null, cve: string|null, affected: string, severity: string|null, reported_at: string|null}>
     *
     * @throws PackagistRequestFailedException
     */
    public function advisories(DataSourceConnection $connection): array
    {
        return StatisticsCache::remember('packagist-advisories', Period::Month, [$connection->id, $this->name($connection)], fn (): array => $this->client->advisories($this->name($connection)));
    }

    /**
     * @return array{name: string, description: string, repository: string|null, downloads: array{total: int, monthly: int, daily: int}, favers: int, dependents: int, suggesters: int, github_stars: int|null, releases: list<array{version: string, time: string|null}>}
     *
     * @throws PackagistRequestFailedException
     */
    public function package(DataSourceConnection $connection): array
    {
        return StatisticsCache::remember('packagist-package', Period::Month, [$connection->id, $this->name($connection)], fn (): array => $this->client->package($this->name($connection)));
    }

    /** How many days a period covers; Packagist has nothing finer than a day. */
    public static function count(Period $period): int
    {
        return match ($period) {
            Period::Month => 30,
            Period::Week => 7,
            default => 1,
        };
    }

    /**
     * The days of two periods and a few more, as Packagist is a day or two behind.
     *
     * @return array<string, int>
     *
     * @throws PackagistRequestFailedException
     */
    private function days(DataSourceConnection $connection, Period $period): array
    {
        $count = self::count($period);

        return StatisticsCache::remember('packagist-daily', Period::Month, [$connection->id, $this->name($connection), $count], fn (): array => $this->client->daily($this->name($connection), self::from(2 * $count)));
    }

    private static function from(int $days): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay()->subDays($days + 3);
    }

    private function name(DataSourceConnection $connection): string
    {
        return $connection->packagistConfig()->package;
    }
}
