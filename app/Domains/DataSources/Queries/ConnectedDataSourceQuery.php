<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Queries;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Shared\Enums\Period;

/**
 * What a connected source last answered for a period, for screen widgets. Asking is also
 * what gets a period pulled: the app is only ever called for what a screen shows.
 */
class ConnectedDataSourceQuery
{
    /** How often asking again is written down; a screen asks every few seconds. */
    private const int TOUCH_MINUTES = 4;

    /** The most points a chart asks an app for; the package refuses more. */
    private const int MOST_POINTS = 999;

    /**
     * @param  int|null  $resolution  seconds per point; null, or one that does not fit the period, is the period's own
     */
    public function window(DataSource $source, Period $period, ?int $resolution = null): DataSourceWindow
    {
        $window = DataSourceWindow::query()->firstOrCreate(
            ['data_source_id' => $source->id, 'period' => $period->value, 'bucket' => $this->bucket($period, $resolution)],
            ['requested_at' => now()],
        );

        if ($window->requested_at->lt(now()->subMinutes(self::TOUCH_MINUTES))) {
            $window->update(['requested_at' => now()]);
        }

        return $window;
    }

    private function bucket(Period $period, ?int $resolution): int
    {
        $seconds = $period->bucketSeconds() * $period->buckets();

        return $resolution !== null && $resolution > 0 && $resolution < $seconds && $seconds / $resolution <= self::MOST_POINTS
            ? $resolution
            : $period->bucketSeconds();
    }

    /**
     * @return list<array{t: string, v: float|null}>
     */
    public function series(DataSourceWindow $window): array
    {
        return array_values(array_map(fn (array $point): array => [
            't' => gmdate('Y-m-d\TH:i:s\Z', (int) $point['at']),
            'v' => $point['value'] === null ? null : (float) $point['value'],
        ], is_array($window->data) ? $window->data : []));
    }

    /**
     * A list as it is; a table by its first column as the label and its second as the value.
     *
     * @return list<array{label: string, value: float|string|null}>
     */
    public function rows(DataSourceWindow $window): array
    {
        $data = is_array($window->data) ? $window->data : [];
        $rows = isset($data['columns'])
            ? array_map(fn (array $row): array => ['label' => (string) ($row[0] ?? ''), 'value' => $row[1] ?? null], $data['rows'] ?? [])
            : $data;

        return array_values(array_map(fn (array $row): array => ['label' => (string) $row['label'], 'value' => is_int($row['value']) || is_float($row['value']) ? (float) $row['value'] : (is_string($row['value']) ? $row['value'] : null)], $rows));
    }
}
