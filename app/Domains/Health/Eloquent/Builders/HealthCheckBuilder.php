<?php

declare(strict_types=1);

namespace App\Domains\Health\Eloquent\Builders;

use App\Domains\Health\Enums\HealthCheckSource;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use Carbon\Carbon;
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
}
