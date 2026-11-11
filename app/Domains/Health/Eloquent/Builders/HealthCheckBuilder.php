<?php

declare(strict_types=1);

namespace App\Domains\Health\Eloquent\Builders;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Enums\HealthCheckSource;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<HealthCheck>
 */
class HealthCheckBuilder extends Builder
{
    public function whereNotArchived(): self
    {
        return $this->whereNull('archived_at');
    }

    public function orWhereNotArchived(): self
    {
        return $this->orWhereNull('archived_at');
    }

    /**
     * Degraded or down as of its last result.
     */
    public function whereUnhealthy(): self
    {
        return $this->whereIn('status', [HealthStatus::Degraded->value, HealthStatus::Down->value]);
    }

    public function orWhereUnhealthy(): self
    {
        return $this->orWhereIn('status', [HealthStatus::Degraded->value, HealthStatus::Down->value]);
    }

    /**
     * Reported checks that have sent nothing since the given time (or never, if older).
     */
    public function whereReportedSilentSince(?CarbonInterface $datetime = null): self
    {
        $since = $datetime ?? now();

        return $this->where('source', HealthCheckSource::Reported->value)
            ->where(fn (self $q) => $q->where('last_result_at', '<', $since)->orWhere(fn (self $n) => $n->whereNull('last_result_at')->where('created_at', '<', $since)));
    }

    public function orWhereReportedSilentSince(?CarbonInterface $datetime = null): self
    {
        return $this->orWhere(fn (self $q) => $q->whereReportedSilentSince($datetime));
    }

    public function whereMonitored(): self
    {
        return $this->where('source', HealthCheckSource::Monitored->value);
    }

    public function orWhereMonitored(): self
    {
        return $this->orWhere('source', HealthCheckSource::Monitored->value);
    }

    /**
     * Checks Lumberjack runs whose next run has come.
     */
    public function whereDue(?Carbon $datetime = null): self
    {
        return $this->whereMonitored()->whereNotArchived()->where(fn (self $q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $datetime ?? now()));
    }

    public function orWhereDue(?Carbon $datetime = null): self
    {
        return $this->orWhere(fn (self $q) => $q->whereDue($datetime));
    }

    /**
     * Data source checks that read the given source.
     */
    public function whereReadsDataSource(DataSource $source): self
    {
        return $this->where('type', HealthCheckType::DataSource->value)
            ->where('project_id', $source->project_id)
            ->where('config->data_source', $source->uuid);
    }

    public function orWhereReadsDataSource(DataSource $source): self
    {
        return $this->orWhere(fn (self $q) => $q->whereReadsDataSource($source));
    }

    /**
     * Data source checks that read their connected source over the given period.
     */
    public function whereReadsPeriod(string $period): self
    {
        return $this->where('type', HealthCheckType::DataSource->value)->where('config->period', $period);
    }

    public function orWhereReadsPeriod(string $period): self
    {
        return $this->orWhere(fn (self $q) => $q->whereReadsPeriod($period));
    }

    /**
     * Checks whose latest result on some host carried database figures (size_bytes or
     * connections), such as an SDK's database check.
     */
    public function whereReportsDatabase(): self
    {
        return $this->whereHas('hosts', fn (Builder $q) => $q->whereRaw("((last_metrics->>'size_bytes') IS NOT NULL OR (last_metrics->>'connections') IS NOT NULL)"));
    }

    public function orWhereReportsDatabase(): self
    {
        return $this->orWhere(fn (self $q) => $q->whereReportsDatabase());
    }
}
