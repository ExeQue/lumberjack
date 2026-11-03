<?php

declare(strict_types=1);

namespace App\Domains\Health\Enums;

/**
 * Where a check stands after its thresholds: a single bad result does not move it.
 * Recovering is a degraded or down check with good results in a row, not yet enough
 * of them to be ok again.
 */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Recovering = 'recovering';
    case Degraded = 'degraded';
    case Down = 'down';

    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Recovering => 1,
            self::Degraded => 2,
            self::Down => 3,
        };
    }

    public function worseThan(?self $other): bool
    {
        return $this->rank() > ($other?->rank() ?? 0);
    }

    /**
     * @param  iterable<self|null>  $statuses
     */
    public static function worst(iterable $statuses): ?self
    {
        $worst = null;

        foreach ($statuses as $status) {
            if ($status !== null && ($worst === null || $status->rank() > $worst->rank())) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
