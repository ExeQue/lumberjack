<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

/**
 * SQL fragments for nearest-rank percentiles over a ROW_NUMBER() window, so one
 * ranked CTE gives several percentiles at once.
 */
final class Percentiles
{
    public static function select(string $column, float $percentile, string $alias): string
    {
        $p = number_format($percentile, 2, '.', '');

        return "MAX(CASE WHEN rn = GREATEST(1, CEIL(cnt * {$p})) THEN {$column} END) AS {$alias}";
    }
}
