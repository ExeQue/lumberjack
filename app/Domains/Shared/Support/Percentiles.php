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
    public static function select(string $column, float $percentile, string $alias): string
    {
        return self::of($column, $percentile)." AS {$alias}";
    }

    public static function of(string $column, float $percentile): string
    {
        $p = number_format($percentile, 2, '.', '');

        return "percentile_disc({$p}) WITHIN GROUP (ORDER BY {$column})";
    }
}
