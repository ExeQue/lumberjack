<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Queries\ConnectedDataSourceQuery;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Shared\Enums\Period;

/**
 * A data source check starts reading a connected source's period (added, changed or back
 * from the archive): the period is wanted from now, so the check waits for its next pull.
 */
class WantDataSourcePeriodAction
{
    public function __construct(
        private readonly ConnectedDataSourceQuery $connected,
    ) {
    }

    public function execute(HealthCheck $check): void
    {
        $period = Period::tryFrom((string) $check->setting('period'));

        if ($check->type !== HealthCheckType::DataSource || $check->archived_at !== null || $period === null) {
            return;
        }

        $source = DataSource::query()->whereReadBy($check)
            ->where('format', DataSourceFormat::Connected)->whereNotArchived()->with('dataSourceConnection')->first();

        if ($source !== null) {
            $this->connected->want($source, $period);
        }
    }
}
