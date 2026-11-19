<?php

declare(strict_types=1);

namespace App\Domains\Ai\Data;

use Carbon\CarbonImmutable;

/**
 * Where a budget stands in its current period: what it used, what it may use, and what
 * it will have used by the period's end if it goes on as it has (a straight line).
 */
final readonly class AiBudgetUsage
{
    /**
     * Too early in the period, a straight line says little: one busy minute after midnight
     * would project a hundred times the budget.
     */
    public const float PROJECTION_FROM = 0.1;

    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public float $spent,
        public float $amount,
        /** The share of the period gone, 0 to 1. */
        public float $elapsed,
    ) {
    }

    /** Whether the period is over, so there is no pace left to tell. */
    public function ended(): bool
    {
        return $this->end->lessThanOrEqualTo(now());
    }

    public function percent(): float
    {
        return $this->amount > 0 ? $this->spent / $this->amount * 100 : 0.0;
    }

    /** Spent at the period's end at the pace so far; what was spent until enough of it went by to say. */
    public function projected(): float
    {
        return $this->elapsed >= self::PROJECTION_FROM ? $this->spent / $this->elapsed : $this->spent;
    }

    /** On course to go over, and not over yet. */
    public function projectsOverrun(): bool
    {
        return $this->elapsed >= self::PROJECTION_FROM && $this->spent < $this->amount && $this->projected() > $this->amount;
    }

    /**
     * @return array{start: string, end: string, spent: float, amount: float, percent: float, projected: float}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'spent' => $this->spent,
            'amount' => $this->amount,
            'percent' => round($this->percent(), 1),
            'projected' => $this->projected(),
        ];
    }
}
