<?php

declare(strict_types=1);

namespace App\Domains\Shared\Enums;

use Carbon\CarbonImmutable;

enum Period: string
{
    case Hour = '1h';
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public function since(): CarbonImmutable
    {
        return match ($this) {
            self::Hour => now()->subHour(),
            self::Day => now()->subDay(),
            self::Week => now()->subDays(7),
            self::Month => now()->subDays(30),
        };
    }

    /**
     * Bucket size for time series, in seconds.
     */
    public function bucketSeconds(): int
    {
        return match ($this) {
            self::Hour => 120,
            self::Day => 3600,
            self::Week => 6 * 3600,
            self::Month => 86400,
        };
    }

    public function buckets(): int
    {
        return match ($this) {
            self::Hour => 30,
            self::Day => 24,
            self::Week => 28,
            self::Month => 30,
        };
    }
}
