<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

/**
 * SQL for nearest-rank percentiles as ordered-set aggregates: percentile_disc picks the
 * first value whose rank reaches the fraction, so no window has to rank every row.
 * Postgres sorts once for several percentiles of the same column in one query.
 */
final class Percentiles
{
    /**
     * @param  literal-string  $column
     * @param  literal-string  $percentile  a fraction such as '0.95'
     * @param  literal-string  $alias
     * @param  literal-string|null  $filter  a condition only the rows it lets in are ranked by, e.g. 'occurred_at >= ?'
     * @return literal-string
     */
    public static function select(string $column, string $percentile, string $alias, ?string $filter = null): string
    {
        $filter = $filter !== null ? " FILTER (WHERE {$filter})" : '';

        return "percentile_disc({$percentile}) WITHIN GROUP (ORDER BY {$column}){$filter} AS {$alias}";
    }
}
