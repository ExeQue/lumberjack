<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Actions;

use App\Domains\Connectors\Packagist\Enums\PackagistSource;
use App\Domains\Connectors\Packagist\Exceptions\PackagistRequestFailedException;
use App\Domains\Connectors\Packagist\Queries\PackagistDashboardQuery;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;

/**
 * One of a Packagist package's sources over a range, as an app would answer for it.
 */
class PullPackagistSourceAction
{
    public function __construct(
        private readonly PackagistDashboardQuery $query,
    ) {
    }

    /**
     * Packagist counts whole days, so a range is read as the last day, week or month it has
     * published. A series has a point per day whatever the bucket.
     *
     * @return array{shape: string, data: mixed}
     *
     * @throws PackagistRequestFailedException
     */
    public function execute(DataSource $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $connection = $source->dataSourceConnection ?? throw new PackagistRequestFailedException(__('connectors.packagist.errors.unknown_source'));
        $kind = PackagistSource::tryFrom((string) $source->key) ?? throw new PackagistRequestFailedException(__('connectors.packagist.errors.unknown_source'));
        $days = (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
        $period = match (true) {
            $days > 7 => Period::Month,
            $days > 1 => Period::Week,
            default => Period::Day,
        };

        if ($kind === PackagistSource::DownloadsSeries) {
            return ['shape' => DataSourceShape::Series->value, 'data' => array_map(
                fn (array $point): array => ['at' => CarbonImmutable::parse($point['t'])->getTimestamp(), 'value' => $point['downloads']],
                $this->query->series($connection, $period),
            )];
        }

        $summary = $this->query->summary($connection, $period);

        return ['shape' => DataSourceShape::Number->value, 'data' => match ($kind) {
            PackagistSource::Downloads => $summary['downloads'],
            PackagistSource::TotalDownloads => $summary['total'],
            PackagistSource::Favers => $summary['favers'],
            PackagistSource::Dependents => $summary['dependents'],
            PackagistSource::Advisories => $summary['advisories'],
        }];
    }
}
