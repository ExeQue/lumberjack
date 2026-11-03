<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;

/**
 * The dice and the clock of the showcase seeders: every random pick goes through
 * mt_rand (seeded once), so a seed run gives the same data each time, laid out
 * relative to the minute it runs in.
 */
final class Showcase
{
    private static ?CarbonImmutable $now = null;

    /** Seeds mt_rand and Faker, and fixes "now" for this run. */
    public static function start(): void
    {
        mt_srand(ShowcaseCatalog::SEED);
        fake()->seed(ShowcaseCatalog::SEED);
        self::$now = CarbonImmutable::now()->startOfMinute();
    }

    public static function now(): CarbonImmutable
    {
        return self::$now ??= CarbonImmutable::now()->startOfMinute();
    }

    /**
     * The catalog's project, if it exists.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function project(array $spec): ?Project
    {
        $team = Team::query()->where('name', $spec['team'])->first();

        return $team?->projects()->where('slug', $spec['slug'])->first();
    }

    /**
     * The catalog's projects that exist, with their specs.
     *
     * @return list<array{0: Project, 1: array<string, mixed>}>
     */
    public static function projects(): array
    {
        $found = [];

        foreach (ShowcaseCatalog::projects() as $spec) {
            $project = self::project($spec);

            if ($project !== null) {
                $found[] = [$project, $spec];
            }
        }

        return $found;
    }

    /**
     * How busy a project is at a time, 0 to about 1.1: quiet at night, a peak around
     * midday, a second one in the evening for a shop, less on weekends.
     */
    public static function load(CarbonImmutable $at, string $shape = 'web'): float
    {
        $local = $at->setTimezone('Europe/Copenhagen');
        $hour = $local->hour + $local->minute / 60;
        $weekend = $local->isWeekend();

        $day = match ($shape) {
            // Business software: office hours.
            'office' => 0.06 + 0.94 * self::bump($hour, 11, 3.2) + 0.5 * self::bump($hour, 15, 2),
            // A worker runs all day, busier while the warehouse is open.
            'worker' => 0.35 + 0.65 * self::bump($hour, 12, 4.5),
            // Shops and sites: midday and evening.
            default => 0.08 + 0.85 * self::bump($hour, 12.5, 3.2) + 0.75 * self::bump($hour, 20.5, 2),
        };

        $week = $weekend ? ($shape === 'office' ? 0.3 : ($shape === 'worker' ? 0.7 : 0.8)) : 1.0;

        return min(1.15, $day * $week);
    }

    /**
     * The release a project ran at a time, by its catalog's deploys.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function release(array $spec, CarbonImmutable $at): ?string
    {
        $current = null;
        $days = self::now()->diffInMinutes($at, true) / 1440;

        foreach ($spec['releases'] as $release) {
            if ($release[1] >= $days) {
                $current = $release[0];
            }
        }

        return $current ?? $spec['releases'][0][0] ?? null;
    }

    /**
     * When the bad release ran, if the project had one.
     *
     * @param  array<string, mixed>  $spec
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}|null
     */
    public static function badRelease(array $spec): ?array
    {
        foreach ($spec['releases'] as $i => $release) {
            if (($release[2] ?? null) === 'bad') {
                $next = $spec['releases'][$i + 1] ?? null;

                return [self::daysAgo($release[1]), $next !== null ? self::daysAgo($next[1]) : self::now(), $release[0]];
            }
        }

        return null;
    }

    public static function daysAgo(float $days): CarbonImmutable
    {
        return self::now()->subSeconds((int) round($days * 86400));
    }

    public static function chance(float $p): bool
    {
        return $p > 0 && mt_rand() / mt_getrandmax() < $p;
    }

    /** Uniform between 0 and 1. */
    public static function unit(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    /** A count around $mean, as arrivals in a minute come. */
    public static function poisson(float $mean): int
    {
        if ($mean <= 0) {
            return 0;
        }

        if ($mean > 30) {
            return max(0, (int) round($mean + sqrt($mean) * self::gauss()));
        }

        $limit = exp(-$mean);
        $k = 0;

        for ($p = self::unit(); $p > $limit; $p *= self::unit()) {
            $k++;
        }

        return $k;
    }

    public static function gauss(): float
    {
        return sqrt(-2 * log(max(1e-9, self::unit()))) * cos(2 * M_PI * self::unit());
    }

    /** A duration in microseconds around a median in milliseconds, with a long tail. */
    public static function latency(float $medianMs, float $spread = 0.55): int
    {
        return max(200, (int) round($medianMs * 1000 * exp($spread * self::gauss())));
    }

    /**
     * One of the keys, picked by weight.
     *
     * @param  array<int|string, float|int>  $weights
     */
    public static function pick(array $weights): int|string
    {
        $total = array_sum($weights);
        $roll = self::unit() * $total;

        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /**
     * One of the items, any of them as likely.
     *
     * @param  array<int, mixed>  $items
     */
    public static function any(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /** A W3C trace id. */
    public static function traceId(): string
    {
        return sprintf('%08x%08x%08x%08x', mt_rand(), mt_rand(), mt_rand(), mt_rand());
    }

    public static function uuid(): string
    {
        $hex = sprintf('%08x%08x%08x%08x', mt_rand(), mt_rand(), mt_rand(), mt_rand());

        return sprintf('%s-%s-4%s-%x%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), 8 + mt_rand(0, 3), substr($hex, 17, 3), substr($hex, 20, 12));
    }

    /** A timestamp as the telemetry tables store it, to the millisecond. */
    public static function ms(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.v');
    }

    private static function bump(float $hour, float $center, float $width): float
    {
        return exp(-(($hour - $center) ** 2) / (2 * $width ** 2));
    }
}
