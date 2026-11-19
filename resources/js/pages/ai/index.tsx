import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { referenceLabel } from '@/components/lj/activity-filters';
import type { Reference } from '@/components/lj/activity-filters';
import {
    AI_PERIODS,
    AiCharts,
    AiSummaryTiles,
    KeyLookupButton,
    TableSkeleton,
    keyName,
    useChange,
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
    Input,
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
import { show as actorShow } from '@/routes/ai/actors';
import { show as keyShow } from '@/routes/ai/keys';
import { show as ownerShow } from '@/routes/ai/owners';
import { index as pricesIndex } from '@/routes/ai/prices';
import { show as projectShow } from '@/routes/projects';
import type {
    DashboardFilters,
    Paginated,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Table = 'keys' | 'owners' | 'actors';

type KeyRow = AiTotals & { key: AiKeyRow | null; previous_cost: number };
type ReferenceRow = AiTotals & {
    reference: Reference | null;
    previous_cost: number;
};

type Filters = DashboardFilters & {
    keys_search: string;
    owners_search: string;
    actors_search: string;
};

type Props = {
    project: ProjectHeader;
    filters: Filters;
    has_data: boolean;
    docs_url: string;
    lookup_drivers: string[];
    /** Deferred, as are the chart and the tables. */
    summary?: AiSummary;
    chart?: AiChart;
    keys?: Paginated<KeyRow>;
    owners?: Paginated<ReferenceRow>;
    actors?: Paginated<ReferenceRow>;
    can_update: boolean;
};

const SEARCH_DELAY_MS = 300;

const pagerClass =
    'inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 text-ink';

export default function AiIndex({
    project,
    filters,
    has_data,
    docs_url,
    lookup_drivers,
    summary,
    chart,
    keys,
    owners,
    actors,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const query = { period: filters.period, environment: filters.environment };

    useProjectLive(
        project.uuid,
        ['ai.changed'],
        ['summary', 'chart', 'keys', 'owners', 'actors'],
    );

    const referenceLink = (kind: 'owner' | 'actor') => (row: ReferenceRow) =>
        row.reference === null
            ? null
            : (kind === 'owner' ? ownerShow : actorShow)(project.uuid, {
                  query: { ...query, ...row.reference },
              }).url;

    return (
        <>
            <Head title={`${t('ai.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('ai.title') },
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
                    title={t('ai.title')}
                    description={t('ai.description')}
                    actions={
                        has_data && (
                            <KeyLookupButton
                                project={project.uuid}
                                drivers={lookup_drivers}
                            />
                        )
                    }
                />

                {!has_data ? (
                    <Card>
                        <EmptyState title={t('ai.empty_title')}>
                            <p>{t('ai.empty_body')}</p>
                            <a
                                href={docs_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-2 inline-block text-amber hover:underline"
                            >
                                {t('ai.empty_docs')}
                                <span className="sr-only">
                                    {` ${t('ai.opens_new_tab')}`}
                                </span>
                            </a>
                        </EmptyState>
                    </Card>
                ) : (
                    <>
                        <AiSummaryTiles
                            summary={summary}
                            pricesHref={pricesIndex(project.uuid).url}
                        />
                        <AiCharts chart={chart} period={filters.period} />
                        <TopTable<KeyRow>
                            table="keys"
                            page={keys}
                            search={filters.keys_search}
                            nameHeader={t('ai.columns.key')}
                            name={(row) =>
                                row.key === null ? (
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
                                                        row.key.uuid,
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
                                            {row.key.provider &&
                                                ` · ${row.key.provider}`}
                                            {row.key.owner &&
                                                ` · ${referenceLabel(row.key.owner)}`}
                                        </span>
                                    </>
                                )
                            }
                        />
                        <TopTable<ReferenceRow>
                            table="owners"
                            page={owners}
                            search={filters.owners_search}
                            nameHeader={t('ai.columns.owner')}
                            name={(row) => (
                                <ReferenceName
                                    reference={row.reference}
                                    href={referenceLink('owner')(row)}
                                    none={t('ai.tables.no_owner')}
                                />
                            )}
                        />
                        <TopTable<ReferenceRow>
                            table="actors"
                            page={actors}
                            search={filters.actors_search}
                            nameHeader={t('ai.columns.actor')}
                            name={(row) => (
                                <ReferenceName
                                    reference={row.reference}
                                    href={referenceLink('actor')(row)}
                                    none={t('ai.tables.no_actor')}
                                />
                            )}
                        />
                    </>
                )}
            </PageBody>
        </>
    );
}

function ReferenceName({
    reference,
    href,
    none,
}: {
    reference: Reference | null;
    href: string | null;
    none: string;
}) {
    return reference === null || href === null ? (
        <span className="text-ink-3">{none}</span>
    ) : (
        <Link
            href={href}
            title={`${reference.type}:${reference.id}`}
            className="lj-row-link font-mono text-xs hover:text-amber"
        >
            {referenceLabel(reference)}
        </Link>
    );
}

/**
 * One of the page's tables: costliest first, with its own search and pages, which reload
 * only this table.
 */
function TopTable<Row extends AiTotals & { previous_cost: number }>({
    table,
    page,
    search,
    nameHeader,
    name,
}: {
    table: Table;
    page: Paginated<Row> | undefined;
    search: string;
    nameHeader: string;
    name: (row: Row) => ReactNode;
}) {
    const t = useT();
    const money = useMoney();
    const change = useChange();
    const { url } = usePage();
    const [value, setValue] = useState(search);
    const typed = useRef(false);

    useEffect(() => {
        if (!typed.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            const params = new URLSearchParams(url.split('?')[1] ?? '');

            if (value.trim() === '') {
                params.delete(`${table}_search`);
            } else {
                params.set(`${table}_search`, value.trim());
            }

            params.delete(`${table}_page`);
            router.get(
                `${url.split('?')[0]}?${params.toString()}`,
                {},
                {
                    only: [table, 'filters'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, SEARCH_DELAY_MS);

        return () => window.clearTimeout(timer);
        // Only typing searches; a reload of the page keeps what is typed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    const id = `ai-${table}`;

    return (
        <Card aria-labelledby={id}>
            <CardHeader
                id={id}
                title={t(`ai.tables.${table}`)}
                description={t(`ai.tables.${table}_description`)}
                aside={
                    <Input
                        type="search"
                        aria-label={t(`ai.tables.search_${table}`)}
                        placeholder={t(`ai.tables.search_${table}`)}
                        value={value}
                        onChange={(e) => {
                            typed.current = true;
                            setValue(e.target.value);
                        }}
                        className="w-56"
                    />
                }
            />
            {page === undefined ? (
                <TableSkeleton />
            ) : page.data.length === 0 ? (
                <EmptyState title={t('ai.tables.empty')} />
            ) : (
                <div className="relative overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>{nameHeader}</th>
                                <th
                                    className={cn(
                                        thClass,
                                        'hidden text-right md:table-cell',
                                    )}
                                >
                                    {t('ai.columns.calls')}
                                </th>
                                <th className={cn(thClass, 'text-right')}>
                                    {t('ai.columns.tokens')}
                                </th>
                                <th className={cn(thClass, 'text-right')}>
                                    {t('ai.kpis.cost_ours')}
                                </th>
                                <th className={cn(thClass, 'text-right')}>
                                    {t('ai.kpis.cost_customer')}
                                </th>
                                <th
                                    className={cn(
                                        thClass,
                                        'hidden text-right lg:table-cell',
                                    )}
                                >
                                    {t('ai.columns.change')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {page.data.map((row, i) => (
                                <tr
                                    key={i}
                                    className="lj-row hover:bg-panel-2/60"
                                >
                                    <td className={cn(tdClass, 'text-xs')}>
                                        {name(row)}
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            numClass,
                                            'hidden md:table-cell',
                                        )}
                                    >
                                        {formatNumber(row.calls)}
                                    </td>
                                    <td
                                        className={cn(tdClass, numClass)}
                                        title={formatNumber(row.tokens)}
                                    >
                                        {formatCompact(row.tokens)}
                                    </td>
                                    <td className={cn(tdClass, numClass)}>
                                        {money(row.cost_ours)}
                                    </td>
                                    <td className={cn(tdClass, numClass)}>
                                        {money(row.cost_customer)}
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            numClass,
                                            'hidden text-xs text-ink-2 lg:table-cell',
                                        )}
                                    >
                                        {change(row.cost, row.previous_cost) ||
                                            '-'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {page && (page.prev_page_url || page.next_page_url) && (
                <nav
                    aria-label={t('ai.tables.pages', {
                        table: t(`ai.tables.${table}`),
                    })}
                    className="flex justify-end gap-2 border-t border-line/60 px-3 py-2.5"
                >
                    {page.prev_page_url && (
                        <Link
                            href={page.prev_page_url}
                            only={[table]}
                            preserveState
                            preserveScroll
                            className={pagerClass}
                        >
                            {t('common.previous')}
                        </Link>
                    )}
                    {page.next_page_url && (
                        <Link
                            href={page.next_page_url}
                            only={[table]}
                            preserveState
                            preserveScroll
                            className={pagerClass}
                        >
                            {t('common.next')}
                        </Link>
                    )}
                </nav>
            )}
        </Card>
    );
}
