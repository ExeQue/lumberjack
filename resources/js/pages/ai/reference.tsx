import { BudgetsUsedCard } from '@/components/lj/ai-budgets';
import type { AiBudgetRow } from '@/components/lj/ai-budgets';
import { Head, Link, usePage } from '@inertiajs/react';
import { referenceLabel } from '@/components/lj/activity-filters';
import type { Reference } from '@/components/lj/activity-filters';
import {
    AI_PERIODS,
    AiCharts,
    AiSummaryTiles,
    TableSkeleton,
    keyName,
    useMoney,
} from '@/components/lj/ai-usage';
import type {
    AiChart,
    AiKeyRow,
    AiSummary,
    AiTotals,
} from '@/components/lj/ai-usage';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Card,
    CardHeader,
    EmptyState,
    PageTitle,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatCompact, formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import { index as aiIndex } from '@/routes/ai';
import { show as keyShow } from '@/routes/ai/keys';
import { index as pricesIndex } from '@/routes/ai/prices';
import { show as projectShow } from '@/routes/projects';
import type {
    DashboardFilters,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Props = {
    project: ProjectHeader;
    filters: DashboardFilters;
    kind: 'owner' | 'actor';
    reference: Reference;
    summary?: AiSummary;
    chart?: AiChart;
    by_key?: (AiTotals & { key: AiKeyRow | null })[];
    /** Deferred: the budgets that count this owner or actor. */
    budgets?: AiBudgetRow[];
};

/**
 * One key owner's or one actor's usage and cost, over time per model and per key.
 */
export default function AiReference({
    project,
    filters,
    kind,
    reference,
    summary,
    chart,
    by_key: byKey,
    budgets,
}: Props) {
    const t = useT();
    const money = useMoney();
    const { team } = usePage<{ team: TeamShared }>().props;
    const query = { period: filters.period, environment: filters.environment };
    const title = t(`ai.reference.${kind}_title`, {
        reference: referenceLabel(reference),
    });

    useProjectLive(
        project.uuid,
        ['ai.changed'],
        ['summary', 'chart', 'by_key', 'budgets'],
    );

    return (
        <>
            <Head title={`${title} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    {
                        label: t('ai.title'),
                        href: aiIndex(project.uuid, { query }).url,
                    },
                    { label: referenceLabel(reference) },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                    periods={AI_PERIODS}
                />
            </TopBar>
            <PageBody>
                <PageTitle
                    title={
                        <span>
                            {title}
                            <span className="ml-2 font-mono text-sm font-normal text-ink-3">
                                {reference.type}:{reference.id}
                            </span>
                        </span>
                    }
                    description={t(`ai.reference.${kind}_description`)}
                />

                <AiSummaryTiles
                    summary={summary}
                    pricesHref={pricesIndex(project.uuid).url}
                />
                <AiCharts chart={chart} period={filters.period} />
                <BudgetsUsedCard project={project.uuid} budgets={budgets} />

                <Card aria-labelledby="ai-by-key">
                    <CardHeader
                        id="ai-by-key"
                        title={t('ai.reference.by_key')}
                    />
                    {byKey === undefined ? (
                        <TableSkeleton rows={3} />
                    ) : byKey.length === 0 ? (
                        <EmptyState title={t('ai.no_data')} />
                    ) : (
                        <div className="relative overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('ai.columns.key')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('ai.columns.calls')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('ai.columns.tokens')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('ai.kpis.cost_ours')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('ai.kpis.cost_customer')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {byKey.map((row, i) => (
                                        <tr
                                            key={row.key?.uuid ?? i}
                                            className="lj-row hover:bg-panel-2/60"
                                        >
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs',
                                                )}
                                            >
                                                {row.key === null ? (
                                                    <span className="text-ink-3">
                                                        {t('ai.tables.no_key')}
                                                    </span>
                                                ) : (
                                                    <>
                                                        <Link
                                                            href={
                                                                keyShow(
                                                                    [
                                                                        project.uuid,
                                                                        row.key
                                                                            .uuid,
                                                                    ],
                                                                    { query },
                                                                ).url
                                                            }
                                                            className="lj-row-link hover:text-amber"
                                                        >
                                                            {keyName(row.key)}
                                                        </Link>
                                                        <span className="relative ml-2 font-mono text-[11px] text-ink-3">
                                                            {row.key.short_id}
                                                        </span>
                                                    </>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {formatNumber(row.calls)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                                title={formatNumber(row.tokens)}
                                            >
                                                {formatCompact(row.tokens)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {money(row.cost_ours)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {money(row.cost_customer)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </PageBody>
        </>
    );
}
