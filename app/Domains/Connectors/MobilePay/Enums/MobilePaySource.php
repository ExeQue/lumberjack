<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Enums;

use App\Domains\DataSources\Enums\DataSourceShape;

/**
 * The data sources a Vipps MobilePay ledger offers screens. Amounts are in the ledger's
 * currency, in whole units (kroner, euros) with two decimals.
 */
enum MobilePaySource: string
{
    case Revenue = 'revenue';
    case Transactions = 'transactions';
    case Refunds = 'refunds';
    case Fees = 'fees';
    case Payouts = 'payouts';
    case RevenueSeries = 'revenue.series';

    /** Named with the ledger's currency, as an amount has no unit of its own on a screen. */
    public function label(string $currency): string
    {
        return __('connectors.mobilepay.sources.'.str_replace('.', '_', $this->value), ['currency' => $currency]);
    }

    public function shape(): DataSourceShape
    {
        return $this === self::RevenueSeries ? DataSourceShape::Series : DataSourceShape::Number;
    }

    /**
     * Its figure from a period's totals, which keep amounts in minor units (øre, cents).
     *
     * @param  array{revenue: int, transactions: int, refunds: int, fees: int, payouts: int}  $totals
     */
    public function figure(array $totals): int|float
    {
        return match ($this) {
            self::Transactions => $totals['transactions'],
            self::Revenue, self::RevenueSeries => self::major($totals['revenue']),
            self::Refunds => self::major($totals['refunds']),
            self::Fees => self::major($totals['fees']),
            self::Payouts => self::major($totals['payouts']),
        };
    }

    /**
     * Every figure of a period's totals, amounts in whole units.
     *
     * @param  array{revenue: int, transactions: int, refunds: int, fees: int, payouts: int}  $totals
     * @return array{revenue: float, transactions: int, refunds: float, fees: float, payouts: float}
     */
    public static function figures(array $totals): array
    {
        return [
            'revenue' => self::major($totals['revenue']),
            'transactions' => $totals['transactions'],
            'refunds' => self::major($totals['refunds']),
            'fees' => self::major($totals['fees']),
            'payouts' => self::major($totals['payouts']),
        ];
    }

    /** NOK, DKK and EUR all count 100 minor units to one. */
    private static function major(int $minor): float
    {
        return round($minor / 100, 2);
    }
}
