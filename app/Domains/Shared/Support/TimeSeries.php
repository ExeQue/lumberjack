<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use App\Domains\Shared\Enums\Period;
use Illuminate\Database\Query\Builder;

/**
 * Counts rows into fixed buckets over a period, filling empty buckets with zero.
 */
final class TimeSeries
{
    /**
     * @param  Builder  $query  already constrained to the rows to count
     * @param  literal-string  $column
     * @param  literal-string  $aggregate
     * @return list<array{t: string, v: int|float}>
     */
    public static function count(Builder $query, Period $period, string $column = 'occurred_at', string $aggregate = 'COUNT(*)'): array
    {
        $size = $period->bucketSeconds();
        $since = $period->since();
        $start = intdiv($since->getTimestamp(), $size) * $size;

        $rows = (clone $query)
            ->where($column, '>=', $since)
            ->selectRaw("FLOOR(EXTRACT(EPOCH FROM {$column}) / ?) * ? AS bucket, {$aggregate} AS v", [$size, $size])
            ->groupBy('bucket')
            ->pluck('v', 'bucket');

        $series = [];
        for ($i = 0; $i <= $period->buckets(); $i++) {
            $bucket = $start + $i * $size;

            if ($bucket > now()->getTimestamp()) {
                break;
            }

            $series[] = ['t' => date(DATE_ATOM, $bucket), 'v' => (float) ($rows[$bucket] ?? $rows[(string) $bucket] ?? 0)];
        }

        return $series;
    }
}
