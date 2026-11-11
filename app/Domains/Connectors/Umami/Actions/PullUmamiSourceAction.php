<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Actions;

use App\Domains\Connectors\Umami\Enums\UmamiSource;
use App\Domains\Connectors\Umami\Enums\UtmDimension;
use App\Domains\Connectors\Umami\Exceptions\UmamiRequestFailedException;
use App\Domains\Connectors\Umami\Support\UmamiClient;
use App\Domains\Connectors\Umami\Support\UmamiSeries;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSource;
use Carbon\CarbonImmutable;

/**
 * Reads one source of an Umami connection for a range, answering in the form a connected
 * app does ({shape, data}), so it is checked and stored the same way.
 */
class PullUmamiSourceAction
{
    /** Rows a list shows. */
    private const int LIST_ROWS = 10;

    public function __construct(
        private readonly UmamiClient $client,
    ) {
    }

    /**
     * @return array{shape: string, data: mixed}
     *
     * @throws UmamiRequestFailedException
     */
    public function execute(DataSource $source, CarbonImmutable $from, CarbonImmutable $to, int $bucket, string $timezone): array
    {
        $connection = $source->dataSourceConnection ?? throw new UmamiRequestFailedException(__('connectors.umami.errors.not_found'));
        $credentials = $connection->umamiCredentials();
        $website = $connection->umamiWebsiteId();
        $key = (string) $source->key;

        $series = str_ends_with($key, '.series');

        if (str_starts_with($key, UmamiSource::EVENT_PREFIX)) {
            $event = $this->narrowedTo($source);

            if ($series) {
                return $this->series($this->client->eventSeries($credentials, $website, $event, $from, $to, UmamiSeries::unit($bucket), $timezone), $from, $to, $bucket, $timezone);
            }

            $rows = $this->client->metrics($credentials, $website, 'event', $from, $to, 1, ['event' => $event]);

            return ['shape' => DataSourceShape::Number->value, 'data' => $rows[0]['y'] ?? 0];
        }

        if (str_starts_with($key, UmamiSource::CAMPAIGN_PREFIX)) {
            $filter = ['utmCampaign' => $this->narrowedTo($source)];

            return $series
                ? $this->series($this->client->pageviews($credentials, $website, $from, $to, UmamiSeries::unit($bucket), $timezone, $filter)['sessions'], $from, $to, $bucket, $timezone)
                : ['shape' => DataSourceShape::Number->value, 'data' => $this->client->stats($credentials, $website, $from, $to, $filter)['current']['visitors'] ?? 0];
        }

        $kind = UmamiSource::tryFrom($key) ?? throw new UmamiRequestFailedException(__('connectors.umami.errors.unknown_type'));

        if ($kind === UmamiSource::Active) {
            return ['shape' => DataSourceShape::Number->value, 'data' => $this->client->active($credentials, $website)];
        }

        if ($kind === UmamiSource::VisitorsSeries || $kind === UmamiSource::PageviewsSeries) {
            $points = $this->client->pageviews($credentials, $website, $from, $to, UmamiSeries::unit($bucket), $timezone);

            return $this->series($kind === UmamiSource::VisitorsSeries ? $points['sessions'] : $points['pageviews'], $from, $to, $bucket, $timezone);
        }

        if ($kind->metricType() !== null) {
            // A UTM value is listed by the name it was given.
            $labels = UtmDimension::tryFrom($kind->metricType()) === null ? [] : $connection->utmLabels()->where('dimension', $kind->metricType())->pluck('label', 'value')->all();

            return ['shape' => DataSourceShape::List->value, 'data' => array_map(
                fn (array $row): array => ['label' => $row['x'] === '' ? __('connectors.umami.none') : ($labels[$row['x']] ?? $row['x']), 'value' => $row['y']],
                $this->client->metrics($credentials, $website, $kind->metricType(), $from, $to, self::LIST_ROWS),
            )];
        }

        $stats = $this->client->stats($credentials, $website, $from, $to)['current'];

        return ['shape' => DataSourceShape::Number->value, 'data' => self::figure($kind, $stats)];
    }

    /**
     * One of the totals, or a rate or average made from them.
     *
     * @param  array<string, int|float>  $stats
     */
    public static function figure(UmamiSource $kind, array $stats): int|float|null
    {
        $visits = $stats['visits'] ?? 0;

        return match ($kind) {
            UmamiSource::BounceRate => $visits > 0 ? round(($stats['bounces'] ?? 0) / $visits * 100, 1) : null,
            UmamiSource::VisitDuration => $visits > 0 ? (int) round(($stats['totaltime'] ?? 0) / $visits) : null,
            default => $stats[$kind->value] ?? 0,
        };
    }



    /**
     * @param  list<array{x: string, y: int|float}>  $points
     * @return array{shape: string, data: list<array{at: int, value: int|float}>}
     */
    private function series(array $points, CarbonImmutable $from, CarbonImmutable $to, int $bucket, string $timezone): array
    {
        $sums = UmamiSeries::bucket($points, $from, $to, $bucket, $timezone);

        return ['shape' => DataSourceShape::Series->value, 'data' => array_map(fn (int $at, int|float $value): array => ['at' => $at, 'value' => $value], array_keys($sums), array_values($sums))];
    }

    /** The event or campaign a source is narrowed to, as the catalog recorded it. */
    private function narrowedTo(DataSource $source): string
    {
        return is_string($source->filter_value) && $source->filter_value !== ''
            ? $source->filter_value
            : throw new UmamiRequestFailedException(__('connectors.umami.errors.not_found'));
    }
}
