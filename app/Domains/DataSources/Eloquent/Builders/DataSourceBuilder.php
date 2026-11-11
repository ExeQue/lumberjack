<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Eloquent\Builders;

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\Health\Models\HealthCheck;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<DataSource>
 *
 * @mixin Builder<DataSource>
 */
final class DataSourceBuilder extends Builder
{
    /** Sources a connected app offers. */
    public function whereConnected(): self
    {
        return $this->where('format', DataSourceFormat::Connected);
    }

    public function orWhereConnected(): self
    {
        return $this->orWhere('format', DataSourceFormat::Connected);
    }

    /** Sources someone set up by hand. */
    public function whereNotConnected(): self
    {
        return $this->where('format', '!=', DataSourceFormat::Connected);
    }

    public function orWhereNotConnected(): self
    {
        return $this->orWhere('format', '!=', DataSourceFormat::Connected);
    }

    /**
     * Sources a health check can hold a number from: JSON and Prometheus, fetched on their
     * own interval, and connected ones that answer with one number per period.
     */
    public function whereNumeric(): self
    {
        return $this->where(fn (self $q) => $q->whereIn('format', [DataSourceFormat::Json, DataSourceFormat::Prometheus])
            ->orWhere(fn (self $c) => $c->whereConnected()->where('shape', DataSourceShape::Number)->whereNotArchived()));
    }

    public function orWhereNumeric(): self
    {
        return $this->orWhere(fn (self $q) => $q->whereNumeric());
    }

    /**
     * The source a health check reads: by its uuid, in the check's own project.
     */
    public function whereReadBy(HealthCheck $check): self
    {
        return $this->where('project_id', $check->project_id)->where('uuid', (string) $check->setting('data_source'));
    }

    public function orWhereReadBy(HealthCheck $check): self
    {
        return $this->orWhere(fn (self $q) => $q->whereReadBy($check));
    }

    public function whereNotArchived(): self
    {
        return $this->whereNull('archived_at');
    }

    public function orWhereNotArchived(): self
    {
        return $this->orWhereNull('archived_at');
    }
}
