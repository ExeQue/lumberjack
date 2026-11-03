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
     * @return literal-string
     */
    public static function select(string $column, string $percentile, string $alias): string
    {
        return "percentile_disc({$percentile}) WITHIN GROUP (ORDER BY {$column}) AS {$alias}";
    }
}
