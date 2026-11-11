<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Eloquent\Builders;

use App\Domains\DataSources\Actions\DispatchDueDataSourceWindowsAction;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Health\Eloquent\Builders\HealthCheckBuilder;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Shared\Enums\Period;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<DataSourceWindow>
 *
 * @mixin Builder<DataSourceWindow>
 */
final class DataSourceWindowBuilder extends Builder
{
    /**
     * Periods someone is looking at: a screen asked lately, or a health check reads it.
     */
    public function whereActive(?CarbonInterface $datetime = null): self
    {
        $datetime ??= now();

        return $this->where(fn (self $q) => $q->where('requested_at', '>=', $datetime->toImmutable()->subMinutes(DispatchDueDataSourceWindowsAction::ACTIVE_MINUTES))->orWhereReadByHealthCheck());
    }

    public function orWhereActive(?CarbonInterface $datetime = null): self
    {
        return $this->orWhere(fn (self $q) => $q->whereActive($datetime));
    }

    /**
     * Periods a monitored data source check reads.
     */
    public function whereReadByHealthCheck(): self
    {
        return $this->whereExists(self::healthChecksReading());
    }

    /**
     * Whether a health check reads the window, as a column: read_by_check is 1 or null.
     * Those go first when an app's pulls are rationed, as a check fails on a late one.
     */
    public function selectReadByHealthCheck(): self
    {
        return $this->addSelect(['read_by_check' => self::healthChecksReading()->limit(1)]);
    }

    /**
     * The monitored data source checks reading the outer query's window: its own source, in
     * the check's own project, over its period at the period's own resolution, which is what
     * a check reads.
     *
     * @return HealthCheckBuilder
     */
    private static function healthChecksReading(): HealthCheckBuilder
    {
        return HealthCheck::query()->selectRaw('1')
            ->where('type', HealthCheckType::DataSource->value)->whereMonitored()->whereNotArchived()
            ->whereRaw("health_checks.config->>'data_source' = (select data_sources.uuid::text from data_sources where data_sources.id = data_source_windows.data_source_id and data_sources.project_id = health_checks.project_id)")
            ->whereRaw("health_checks.config->>'period' = data_source_windows.period")
            ->where(function (HealthCheckBuilder $own): void {
                foreach (Period::cases() as $period) {
                    $own->orWhere(fn (HealthCheckBuilder $w) => $w->where('data_source_windows.period', $period->value)->where('data_source_windows.bucket', $period->bucketSeconds()));
                }
            });
    }

    public function orWhereReadByHealthCheck(): self
    {
        return $this->orWhere(fn (self $q) => $q->whereReadByHealthCheck());
    }
}
