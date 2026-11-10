<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Queries\Umami;

use App\Domains\Connectors\Actions\Umami\PullUmamiSourceAction;
use App\Domains\Connectors\Enums\Umami\UmamiSource;
use App\Domains\Connectors\Enums\Umami\UtmDimension;
use App\Domains\Connectors\Exceptions\Umami\UmamiRequestFailedException;
use App\Domains\Connectors\Support\Umami\UmamiClient;
use App\Domains\Connectors\Support\Umami\UmamiSeries;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Ingest\Actions\ProcessIngestBatchAction;
use App\Domains\Performance\Queries\BrowserQuery;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\StatisticsCache;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;

/**
 * The Umami page's figures for a period, read from the project's Umami website and cached
 * like the other statistics pages, joined with what Lumberjack itself saw of the same
 * pages: their Web Vitals.
 */
class UmamiDashboardQuery
{
    /**
     * The breakdowns the page shows, by Umami's name, with the filter a row of each sets
     * when clicked; an entry or exit page filters on the page.
     */
    public const array DIMENSIONS = [
        'path' => 'path', 'entry' => 'path', 'exit' => 'path', 'title' => 'title',
        'referrer' => 'referrer', 'channel' => null, 'utmSource' => 'utmSource', 'utmMedium' => 'utmMedium', 'utmCampaign' => 'utmCampaign',
        'country' => 'country', 'region' => 'region', 'city' => 'city', 'language' => 'language',
        'browser' => 'browser', 'os' => 'os', 'device' => 'device', 'screen' => null,
        'event' => 'event', 'hostname' => 'hostname',
    ];

    private const int ROWS = 10;

    private const int ACTIVE_SECONDS = 30;

    private const int HEATMAP_WEEKS = 4;

    private const int ANNOTATION_SECONDS = 300;

    public function __construct(
        private readonly UmamiClient $client,
        private readonly BrowserQuery $browser,
    ) {
    }

    /**
     * Visitors, visits, page views, bounce rate and time per visit, now and in the period before.
     *
     * @param  array<string, string>  $filters
     * @return array{current: array<string, int|float|null>, previous: array<string, int|float|null>}
     *
     * @throws UmamiRequestFailedException
     * @throws DecryptException when the credentials were saved under another APP_KEY
     */
    public function summary(DataSourceConnection $connection, Period $period, array $filters): array
    {
        return StatisticsCache::remember('umami-summary', $period, $this->args($connection, $filters), function () use ($connection, $period, $filters): array {
            $stats = $this->client->stats($connection->umamiCredentials(), (string) $connection->website_id, $period->since(), now(), $filters);

            return array_map(fn (array $totals): array => array_combine(
                array_map(fn (UmamiSource $s): string => $s->value, self::figures()),
                array_map(fn (UmamiSource $s): int|float|null => PullUmamiSourceAction::figure($s, $totals), self::figures()),
            ), $stats);
        });
    }

    /**
     * Visitors on the site in the last few minutes.
     *
     * @throws UmamiRequestFailedException
     */
    public function active(DataSourceConnection $connection): int
    {
        return (int) Cache::remember('umami-active:'.$connection->id.':'.$connection->website_id, self::ACTIVE_SECONDS, fn (): int => $this->client->active($connection->umamiCredentials(), (string) $connection->website_id));
    }

    /**
     * Visitors and page views per bucket of the period.
     *
     * @param  array<string, string>  $filters
     * @return list<array{t: string, visitors: int|float, pageviews: int|float}>
     *
     * @throws UmamiRequestFailedException
     */
    public function series(DataSourceConnection $connection, Period $period, array $filters): array
    {
        return StatisticsCache::remember('umami-series', $period, $this->args($connection, $filters), function () use ($connection, $period, $filters): array {
            $from = $period->since();
            $to = now();
            $bucket = $period->bucketSeconds();
            $points = $this->client->pageviews($connection->umamiCredentials(), (string) $connection->website_id, $from, $to, UmamiSeries::unit($bucket), $connection->timezone, $filters);
            $visitors = UmamiSeries::bucket($points['sessions'], $from, $to, $bucket, $connection->timezone);
            $pageviews = UmamiSeries::bucket($points['pageviews'], $from, $to, $bucket, $connection->timezone);

            return array_map(fn (int $at): array => [
                't' => gmdate('Y-m-d\TH:i:s\Z', $at),
                'visitors' => $visitors[$at],
                'pageviews' => $pageviews[$at] ?? 0,
            ], array_keys($visitors));
        });
    }

    /**
     * The top values of one breakdown with visitors, visits, page views, bounce rate and
     * time per visit for each; pages also with their Web Vitals as Lumberjack saw them.
     *
     * @param  array<string, string>  $filters
     * @return list<array{name: string, visitors: int|float, visits: int|float, pageviews: int|float, bounce_rate: float|null, visit_duration: int|null, lcp_us?: int|null, vital_views?: int, label?: string|null}>
     *
     * @throws UmamiRequestFailedException
     */
    public function breakdown(DataSourceConnection $connection, string $dimension, Period $period, array $filters): array
    {
        if (! array_key_exists($dimension, self::DIMENSIONS)) {
            return [];
        }

        $rows = StatisticsCache::remember('umami-breakdown', $period, [...$this->args($connection, $filters), $dimension], fn (): array => array_map(fn (array $row): array => [
            'name' => $row['name'],
            'visitors' => $row['visitors'],
            'visits' => $row['visits'],
            'pageviews' => $row['pageviews'],
            'bounce_rate' => $row['visits'] > 0 ? round($row['bounces'] / $row['visits'] * 100, 1) : null,
            'visit_duration' => $row['visits'] > 0 ? (int) round($row['totaltime'] / $row['visits']) : null,
        ], $this->client->expandedMetrics($connection->umamiCredentials(), (string) $connection->website_id, $dimension, $period->since(), now(), self::ROWS, $filters)));

        if (UtmDimension::tryFrom($dimension) !== null) {
            // Read after the cache, so a name shows as soon as it is given.
            $labels = $connection->utmLabels()->where('dimension', $dimension)->pluck('label', 'value');

            return array_map(fn (array $row): array => [...$row, 'label' => $labels->get($row['name'])], $rows);
        }

        return in_array($dimension, ['path', 'entry', 'exit'], true) ? $this->withVitals($connection, $rows, $period) : $rows;
    }

    /**
     * The website's annotations in the period, each at the moment it marks: an all-day
     * one at the start of its day in the connection's zone.
     *
     * @return list<array{id: string, at: string, all_day: bool, note: string}>
     *
     * @throws UmamiRequestFailedException
     */
    public function annotations(DataSourceConnection $connection, Period $period): array
    {
        $all = Cache::remember(self::annotationsKey($connection), self::ANNOTATION_SECONDS, fn (): array => $this->client->annotations($connection->umamiCredentials(), (string) $connection->website_id));
        $since = $period->since();

        return array_values(array_filter(array_map(fn (array $a): array => [
            ...$a,
            'at' => ($a['all_day'] ? CarbonImmutable::parse(substr($a['at'], 0, 10), $connection->timezone) : CarbonImmutable::parse($a['at']))->toIso8601String(),
        ], $all), fn (array $a): bool => CarbonImmutable::parse($a['at'])->betweenIncluded($since, now())));
    }

    /** Read again on the next page, after a note was written or removed. */
    public static function forgetAnnotations(DataSourceConnection $connection): void
    {
        Cache::forget(self::annotationsKey($connection));
    }

    private static function annotationsKey(DataSourceConnection $connection): string
    {
        return 'umami-annotations:'.$connection->id.':'.$connection->website_id;
    }

    /**
     * Visitors per hour of the week over the last four weeks, so each weekday is counted
     * four times; by the wall clock of the connection's zone, Monday first.
     *
     * @param  array<string, string>  $filters
     * @return list<list<int|float>> seven days of 24 hours
     *
     * @throws UmamiRequestFailedException
     */
    public function heatmap(DataSourceConnection $connection, array $filters): array
    {
        return StatisticsCache::remember('umami-heatmap', Period::Month, $this->args($connection, $filters), function () use ($connection, $filters): array {
            $grid = array_fill(0, 7, array_fill(0, 24, 0));
            $from = now()->setTimezone($connection->timezone)->subWeeks(self::HEATMAP_WEEKS)->startOfHour();
            $points = $this->client->pageviews($connection->umamiCredentials(), (string) $connection->website_id, $from, now(), 'hour', $connection->timezone, $filters)['sessions'];

            foreach ($points as $point) {
                $at = UmamiSeries::localTime($point['x'], $connection->timezone);
                $grid[$at->dayOfWeekIso - 1][$at->hour] += $point['y'];
            }

            // Writes by index keep the keys in order, but the list type is only known after array_values.
            return array_values(array_map(array_values(...), $grid));
        });
    }

    /**
     * Each page's LCP as the browser script measured it, matched on the path with ids
     * folded the way the script's routes are.
     *
     * @param  list<array{name: string, visitors: int|float, visits: int|float, pageviews: int|float, bounce_rate: float|null, visit_duration: int|null}>  $rows
     * @return list<array{name: string, visitors: int|float, visits: int|float, pageviews: int|float, bounce_rate: float|null, visit_duration: int|null, lcp_us: int|null, vital_views: int}>
     */
    private function withVitals(DataSourceConnection $connection, array $rows, Period $period): array
    {
        $vitals = collect($this->browser->pages($connection->project_id, $period))->keyBy('route');

        return array_map(function (array $row) use ($vitals): array {
            $page = $vitals->get(ProcessIngestBatchAction::normalizePath($row['name']));

            return [...$row, 'lcp_us' => $page['lcp_us'] ?? null, 'vital_views' => $page['views'] ?? 0];
        }, $rows);
    }

    /** @return list<UmamiSource> */
    private static function figures(): array
    {
        return [UmamiSource::Visitors, UmamiSource::Visits, UmamiSource::Pageviews, UmamiSource::BounceRate, UmamiSource::VisitDuration];
    }

    /**
     * What the figures depend on besides the period: the website, and the filters in a fixed order.
     *
     * @param  array<string, string>  $filters
     * @return list<scalar|null>
     */
    private function args(DataSourceConnection $connection, array $filters): array
    {
        ksort($filters);

        return [$connection->id, $connection->website_id, $connection->timezone, http_build_query($filters)];
    }
}
