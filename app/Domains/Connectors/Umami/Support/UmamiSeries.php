<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Support;

use Carbon\CarbonImmutable;

/**
 * Umami counts per minute, hour or day; Lumberjack charts in buckets of its own length.
 */
final class UmamiSeries
{
    /**
     * The finest unit Umami counts in that still fits a bucket.
     *
     * @return 'minute'|'hour'|'day'
     */
    public static function unit(int $bucket): string
    {
        return match (true) {
            $bucket < 3600 => 'minute',
            $bucket < 86_400 => 'hour',
            default => 'day',
        };
    }

    /**
     * Umami's points, each a local time in the zone, added up into buckets that begin at
     * local midnight, a point for every bucket in the range.
     *
     * ponytail: a day with a DST change gets a bucket an hour off; days themselves stay right.
     *
     * @param  list<array{x: string, y: int|float}>  $points
     * @return array<int, int|float> the sum per bucket, by the bucket's start
     */
    public static function bucket(array $points, CarbonImmutable $from, CarbonImmutable $to, int $bucket, string $timezone): array
    {
        $start = function (CarbonImmutable $at) use ($bucket, $timezone): int {
            $midnight = $at->setTimezone($timezone)->startOfDay()->getTimestamp();

            return $bucket >= 86_400 ? $midnight : $midnight + intdiv($at->getTimestamp() - $midnight, $bucket) * $bucket;
        };
        $sums = [];

        for ($at = $start($from); $at < $to->getTimestamp(); $at = $bucket >= 86_400 ? CarbonImmutable::createFromTimestamp($at, $timezone)->addDay()->getTimestamp() : $at + $bucket) {
            $sums[$at] = 0;
        }

        foreach ($points as $point) {
            $at = $start(self::localTime($point['x'], $timezone));

            if (array_key_exists($at, $sums)) {
                $sums[$at] += $point['y'];
            }
        }

        return $sums;
    }

    /**
     * A point's time. Umami gives the wall-clock time in the zone it was asked for, yet
     * writes it with a Z, as if it were UTC: 07:00 in Copenhagen comes as 07:00:00Z.
     */
    public static function localTime(string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse((string) preg_replace('/(?:Z|[+-]\d{2}:?\d{2})$/', '', $time), $timezone);
    }
}
