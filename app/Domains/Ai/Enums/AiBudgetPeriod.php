<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

use Carbon\CarbonImmutable;

/**
 * How often a budget starts over: each day or each calendar month, in the app's time
 * zone, which is where the daily totals begin their days too.
 */
enum AiBudgetPeriod: string
{
    case Day = 'day';
    case Month = 'month';

    public function label(): string
    {
        return __('ui/ai.enums.budget_period.'.$this->value);
    }

    /**
     * The period a moment falls in: its start (inclusive) and end (exclusive).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function window(CarbonImmutable $at): array
    {
        $start = match ($this) {
            self::Day => $at->startOfDay(),
            self::Month => $at->startOfMonth(),
        };

        return [$start, match ($this) {
            self::Day => $start->addDay(),
            self::Month => $start->addMonth(),
        }];
    }
}
