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
    case AverageOrder = 'average_order';
    case RefundRate = 'refund_rate';
    case FeeRate = 'fee_rate';
    case Balance = 'balance';
    case LatestTransactions = 'latest_transactions';
    case LargestSales = 'largest_sales';
    case PayoutList = 'payout_list';
    case SalesByHour = 'sales_by_hour';
    case SalesByWeekday = 'sales_by_weekday';
    case RevenueByUnit = 'revenue_by_unit';

    /** Named with the ledger's currency, as an amount has no unit of its own on a screen. */
    public function label(string $currency): string
    {
        return __('connectors.mobilepay.sources.'.str_replace('.', '_', $this->value), ['currency' => $currency]);
    }

    public function shape(): DataSourceShape
    {
        return match ($this) {
            self::RevenueSeries => DataSourceShape::Series,
            self::LatestTransactions, self::LargestSales, self::PayoutList, self::SalesByHour, self::SalesByWeekday, self::RevenueByUnit => DataSourceShape::List,
            default => DataSourceShape::Number,
        };
    }

    /**
     * Its figure from a period's totals, which keep amounts in minor units (øre, cents); null
     * for a source that is not made from them, or an average or rate of a period without sales.
     *
     * @param  array{revenue: int, transactions: int, refunds: int, fees: int, payouts: int}  $totals
     */
    public function figure(array $totals): int|float|null
    {
        $figures = self::figures($totals);

        return match ($this) {
            self::RevenueSeries => $figures['revenue'],
            self::Revenue, self::Transactions, self::Refunds, self::Fees, self::Payouts, self::AverageOrder, self::RefundRate, self::FeeRate => $figures[$this->value],
            default => null,
        };
    }

    /**
     * Every figure of a period's totals, amounts in whole units: the average order is the
     * revenue per capture, and the refund and fee rates are refunds and fees as a percentage
     * of the revenue, each null without revenue.
     *
     * @param  array{revenue: int, transactions: int, refunds: int, fees: int, payouts: int}  $totals
     * @return array{revenue: float, transactions: int, refunds: float, fees: float, payouts: float, average_order: float|null, refund_rate: float|null, fee_rate: float|null}
     */
    public static function figures(array $totals): array
    {
        $revenue = $totals['revenue'];

        return [
            'revenue' => self::major($revenue),
            'transactions' => $totals['transactions'],
            'refunds' => self::major($totals['refunds']),
            'fees' => self::major($totals['fees']),
            'payouts' => self::major($totals['payouts']),
            'average_order' => $totals['transactions'] > 0 ? round($revenue / $totals['transactions'] / 100, 2) : null,
            'refund_rate' => $revenue > 0 ? round($totals['refunds'] / $revenue * 100, 1) : null,
            'fee_rate' => $revenue > 0 ? round($totals['fees'] / $revenue * 100, 1) : null,
        ];
    }

    /** NOK, DKK and EUR all count 100 minor units to one. */
    public static function major(int $minor): float
    {
        return round($minor / 100, 2);
    }
}
