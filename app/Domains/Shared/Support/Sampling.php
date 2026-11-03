<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use Illuminate\Database\Query\Builder;

/**
 * How sampled rows add up to the traffic they stand for. A row the SDK picked at rate r
 * stands for 1/r rows; a row kept only for being slow or failed (sampled = false) counts
 * once. A slow or failed row that was also picked counts 1/r, so slow and failed rows are
 * overcounted by up to (1 - r) of themselves: a small share of a sampled table.
 */
final class Sampling
{
    /**
     * SQL for one row's weight.
     *
     * @param  literal-string  $table  the alias to qualify the columns with, if any
     * @return literal-string
     */
    public static function weight(string $table = ''): string
    {
        $p = $table !== '' ? $table.'.' : '';

        return "CASE WHEN {$p}sampled AND {$p}sample_rate > 0 THEN 1.0 / {$p}sample_rate ELSE 1 END";
    }

    /**
     * The lowest rate among the rows, or null when none of them was sampled below 1:
     * figures from them are then estimates.
     *
     * @param  Builder  $query  already constrained to the rows
     */
    public static function lowestRate(Builder $query): ?float
    {
        $rate = (clone $query)->where('sample_rate', '<', 1)->min('sample_rate');

        return $rate !== null ? round((float) $rate, 4) : null;
    }
}
