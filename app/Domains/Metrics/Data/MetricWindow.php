<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Data;

use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;

/**
 * A time range cut into buckets of the same size, aligned to the epoch so every
 * caller asking for the same range gets the same buckets.
 */
final readonly class MetricWindow
{
    /** Points per series when the interval is "auto". */
    public const int AUTO_POINTS = 300;

    /** The most points one series may have; a finer interval is coarsened to fit. */
    public const int MAX_POINTS = 2000;

    /** Bucket sizes "auto" picks from, in seconds. */
    private const array STEPS = [60, 120, 300, 600, 900, 1800, 3600, 7200, 21600, 43200, 86400];

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $bucketSeconds,
    ) {
    }

    /**
     * @param  int|null  $bucketSeconds  null picks one for about AUTO_POINTS points
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, ?int $bucketSeconds = null): self
    {
        $range = max(60, $to->getTimestamp() - $from->getTimestamp());
        $bucket = $bucketSeconds ?? self::step((int) ceil($range / self::AUTO_POINTS));

        // Whole minutes, so interval() names the bucket exactly.
        $bucket = max(60, $bucket, (int) ceil($range / self::MAX_POINTS));

        return new self($from, $to, (int) ceil($bucket / 60) * 60);
    }

    /**
     * The period the pages show, ending now, in the pages' own buckets.
     */
    public static function forPeriod(Period $period): self
    {
        return new self($period->since(), now(), $period->bucketSeconds());
    }

    /**
     * The same length of time just before this one, for a trend.
     */
    public function previous(): self
    {
        return $this->shift($this->to->getTimestamp() - $this->from->getTimestamp());
    }

    /**
     * The same window the given number of seconds earlier.
     */
    public function shift(int $seconds): self
    {
        return new self($this->from->subSeconds($seconds), $this->to->subSeconds($seconds), $this->bucketSeconds);
    }

    /**
     * Every bucket start in the window, as Unix timestamps.
     *
     * @return list<int>
     */
    public function buckets(): array
    {
        $start = intdiv($this->from->getTimestamp(), $this->bucketSeconds) * $this->bucketSeconds;

        $buckets = [];

        for ($at = $start; $at === $start || $at < $this->to->getTimestamp(); $at += $this->bucketSeconds) {
            $buckets[] = $at;
        }

        return $buckets;
    }

    /**
     * The interval as Grafana writes it: 5m, 1h, 1d.
     */
    public function interval(): string
    {
        return match (true) {
            $this->bucketSeconds % 86400 === 0 => ($this->bucketSeconds / 86400).'d',
            $this->bucketSeconds % 3600 === 0 => ($this->bucketSeconds / 3600).'h',
            default => intdiv($this->bucketSeconds, 60).'m',
        };
    }

    private static function step(int $seconds): int
    {
        foreach (self::STEPS as $step) {
            if ($step >= $seconds) {
                return $step;
            }
        }

        return self::STEPS[array_key_last(self::STEPS)];
    }
}
