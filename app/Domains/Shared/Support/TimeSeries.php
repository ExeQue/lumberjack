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
            ->pluck('v', 'bucket')
            ->all();

        return self::fill($rows, $period, $start);
    }

    /**
     * One series per value of a column, in one query: the series of many projects at once.
     *
     * @param  Builder  $query  already constrained to the rows to count
     * @param  list<int|string>  $keys  every key gets a series, all zero without rows
     * @param  literal-string  $by
     * @param  literal-string  $column
     * @return array<int|string, list<array{t: string, v: int|float}>>
     */
    public static function countBy(Builder $query, Period $period, string $by, array $keys, string $column = 'occurred_at'): array
    {
        $size = $period->bucketSeconds();
        $since = $period->since();
        $start = intdiv($since->getTimestamp(), $size) * $size;
        $rows = [];

        (clone $query)
            ->where($column, '>=', $since)
            ->selectRaw("{$by} AS k, FLOOR(EXTRACT(EPOCH FROM {$column}) / ?) * ? AS bucket, COUNT(*) AS v", [$size, $size])
            ->groupBy('k', 'bucket')
            ->get()
            ->each(function (object $row) use (&$rows): void {
                $rows[$row->k][(int) $row->bucket] = $row->v;
            });

        return array_combine($keys, array_map(fn (int|string $key): array => self::fill($rows[$key] ?? [], $period, $start), $keys));
    }

    /**
     * @param  array<int|string, mixed>  $rows  values by bucket start
     * @return list<array{t: string, v: int|float}>
     */
    private static function fill(array $rows, Period $period, int $start): array
    {
        $size = $period->bucketSeconds();
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
