import { Link } from '@inertiajs/react';
import type { Reference } from '@/components/lj/activity-filters';
import { TableSkeleton, useMoney } from '@/components/lj/ai-usage';
import type { AiKeyRow } from '@/components/lj/ai-usage';
import {
    Badge,
    Card,
    CardHeader,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as budgetsIndex } from '@/routes/ai/budgets';

/** A budget with where it stands in its current day or month. */
export type AiBudgetRow = {
    uuid: string;
    name: string | null;
    /** Its name, or what it counts. */
    title: string;
    scope: 'project' | 'key' | 'owner' | 'actor';
    /**
     * A key budget's keys: `value` is what the form sends back, `key` null for one the
     * project forgot.
     */
    keys: { value: string; short_id: string; key: AiKeyRow | null }[];
    /** An owner or actor budget's owners or actors. */
    references: Reference[];
    period: 'day' | 'month';
    basis: 'cost' | 'tokens';
    /** Whose usage it counts, by who pays for it. */
    payer: 'all' | 'us' | 'customer';
    amount: number;
    usage: {
        start: string;
        end: string;
        spent: number;
        amount: number;
        percent: number;
        projected: number;
    };
};

/** An amount as the budget counts it: money in the team's currency, or tokens. */
export function useBudgetAmount(): (
    budget: AiBudgetRow,
    value: number,
) => string {
    const t = useT();
    const money = useMoney();

    return (budget, value) =>
        budget.basis === 'cost'
            ? money(value)
            : t('ai.budgets.tokens', {
                  count: formatNumber(Math.round(value)),
              });
}

/** How full a budget is: a bar, amber from 80%, red at 100%. */
export function BudgetMeter({ budget }: { budget: AiBudgetRow }) {
    const t = useT();
    const percent = budget.usage.percent;

    return (
        <div className="flex min-w-32 items-center gap-2">
            <div
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.min(100, Math.round(percent))}
                aria-label={t('ai.budgets.used_label', {
                    percent: Math.round(percent),
                })}
                className="h-1.5 flex-1 overflow-hidden rounded-full bg-panel-2"
            >
                <div
                    className={cn(
                        'h-full rounded-full',
                        percent >= 100
                            ? 'bg-err'
                            : percent >= 80
                              ? 'bg-warn'
                              : 'bg-ok',
                    )}
                    style={{ width: `${Math.min(100, percent)}%` }}
                />
            </div>
            <span className="w-12 text-right font-mono text-xs tabular-nums">
                {Math.round(percent)}%
            </span>
        </div>
    );
}

/** Over, or on course to go over: a badge; nothing while it is on track. */
export function BudgetState({ budget }: { budget: AiBudgetRow }) {
    const t = useT();

    if (budget.usage.spent >= budget.usage.amount) {
        return <Badge tone="err">{t('ai.budgets.over')}</Badge>;
    }

    return budget.usage.projected > budget.usage.amount ? (
        <Badge tone="warn">{t('ai.budgets.projected_over')}</Badge>
    ) : null;
}

/**
 * The budgets that count a key, a key owner or an actor, on its page. Nothing when none
 * does.
 */
export function BudgetsUsedCard({
    project,
    budgets,
}: {
    project: string;
    budgets: AiBudgetRow[] | undefined;
}) {
    const t = useT();
    const amount = useBudgetAmount();

    if (budgets === undefined) {
        return (
            <Card aria-busy="true" aria-label={t('ai.budgets.used_title')}>
                <TableSkeleton rows={2} />
            </Card>
        );
    }

    if (budgets.length === 0) {
        return null;
    }

    return (
        <Card aria-labelledby="ai-budgets-used">
            <CardHeader
                id="ai-budgets-used"
                title={t('ai.budgets.used_title')}
                description={t('ai.budgets.used_description')}
                aside={
                    <Link
                        href={budgetsIndex(project).url}
                        className="text-xs text-amber hover:underline"
                    >
                        {t('common.nav.ai_budgets')}
                    </Link>
                }
            />
            <div className="relative overflow-x-auto">
                <table className={tableClass}>
                    <thead>
                        <tr>
                            <th className={thClass}>
                                {t('ai.budgets.columns.budget')}
                            </th>
                            <th className={thClass}>
                                {t('ai.budgets.columns.used')}
                            </th>
                            <th className={cn(thClass, 'text-right')}>
                                {t('ai.budgets.columns.amount')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {budgets.map((budget) => (
                            <tr key={budget.uuid}>
                                <td className={cn(tdClass, 'text-xs')}>
                                    <span className="mr-2">{budget.title}</span>
                                    <BudgetState budget={budget} />
                                </td>
                                <td className={tdClass}>
                                    <BudgetMeter budget={budget} />
                                </td>
                                <td
                                    className={cn(tdClass, numClass, 'text-xs')}
                                >
                                    {amount(budget, budget.usage.spent)} /{' '}
                                    {amount(budget, budget.amount)}{' '}
                                    {t(`ai.budgets.per.${budget.period}`)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}
