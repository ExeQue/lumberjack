<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Eloquent\Builders;

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Models\DataSource;
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

    public function whereNotArchived(): self
    {
        return $this->whereNull('archived_at');
    }

    public function orWhereNotArchived(): self
    {
        return $this->orWhereNull('archived_at');
    }
}
