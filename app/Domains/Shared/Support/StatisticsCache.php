<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use App\Domains\Shared\Enums\Period;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches a statistics page's figures, so a dashboard reloaded on every live broadcast,
 * by several open tabs, sorts the period once per TTL and not once per reload.
 *
 * A live reload shows data at most the fresh TTL old (15 s for the last hour up to 15
 * minutes for 30 days); past it, the stale value is served once while it is recomputed
 * after the response. The key leaves out the period's start: the figures are recomputed
 * with the current window when they refresh, and telemetry only grows, so no write has
 * to forget a key.
 */
final class StatisticsCache
{
    /**
     * @template T
     *
     * @param  list<scalar|null>  $args  everything the figures depend on besides the period
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(string $name, Period $period, array $args, Closure $compute): mixed
    {
        return Cache::flexible('stats:'.$name.':'.$period->value.':'.md5(serialize($args)), self::ttl($period), $compute);
    }

    /**
     * Seconds fresh, then seconds stale.
     *
     * @return array{int, int}
     */
    public static function ttl(Period $period): array
    {
        return match ($period) {
            Period::Hour => [15, 60],
            Period::Day => [60, 300],
            Period::Week => [300, 900],
            Period::Month => [900, 3600],
        };
    }
}
