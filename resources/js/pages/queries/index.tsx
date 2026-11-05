import { Head, router, usePage } from '@inertiajs/react';
import { HighlightedCode } from '@/components/lj/highlighted-code';
import { useEffect, useState } from 'react';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Card,
    EmptyState,
    Input,
    PageTitle,
    Select,
    StatCard,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { SampleNote } from '@/components/lj/sample-note';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatCompact,
    formatDuration,
    formatNumber,
    formatPercent,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { useProjectLive } from '@/lib/use-project-live';
import { show as projectShow } from '@/routes/projects';
import { index as queriesIndex } from '@/routes/queries';
import type {
    DashboardFilters,
    ProjectHeader,
    QueryStat,
    TeamShared,
} from '@/types/lumberjack';

type Filters = DashboardFilters & {
    sort: string;
    search: string;
    connection: string;
};

type Props = {
    project: ProjectHeader;
    filters: Filters;
    connections: string[];
    /** Deferred: computed after the page shows. */
    stats?: {
        rows: QueryStat[];
        total_us: number;
        calls: number;
        distinct: number;
        /** The lowest sample rate of the period's queries; null when none was sampled. */
        sample_rate: number | null;
    };
};

export default function QueriesIndex({
    project,
    filters,
    connections,
    stats: deferred,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [search, setSearch] = useState(filters.search);

    useProjectLive(project.uuid, ['performance.changed'], ['stats']);

    const loading = deferred === undefined;
    const stats = deferred ?? {
        rows: [],
        total_us: 0,
        calls: 0,
        distinct: 0,
        sample_rate: null,
    };

    const apply = (changes: Partial<Filters>) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        ) as Record<string, string>;

        router.get(
            queriesIndex(project.uuid, { query }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(() => apply({ search }), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // ponytail: slowest among the listed rows (top 100), not the whole period.
    const slowest = Math.max(0, ...stats.rows.map((q) => q.max));

    return (
        <>
            <Head title={`${t('queries.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('queries.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody>
                <PageTitle
                    title={t('queries.title')}
                    description={
                        stats.sample_rate !== null && (
                            <SampleNote rate={stats.sample_rate} />
                        )
                    }
                />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3">
                    <StatCard
                        label={t('queries.stats.calls')}
                        value={formatCompact(stats.calls)}
                        note={t('queries.stats.distinct', {
                            count: formatNumber(stats.distinct),
                        })}
                    />
                    <StatCard
                        label={t('queries.stats.total_time')}
                        value={formatDuration(stats.total_us)}
                    />
                    <StatCard
                        label={t('queries.stats.slowest')}
                        value={formatDuration(
                            stats.rows.length ? slowest : null,
                        )}
                    />
                </div>

                <div className="flex flex-wrap gap-2">
                    <label className="sr-only" htmlFor="query-search">
                        {t('queries.search')}
                    </label>
                    <Input
                        id="query-search"
                        type="search"
                        placeholder={t('queries.search')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="min-w-44 flex-1"
                    />
                    <label className="sr-only" htmlFor="query-connection">
                        {t('queries.connection')}
                    </label>
                    <Select
                        id="query-connection"
                        value={filters.connection}
                        onChange={(e) => apply({ connection: e.target.value })}
                    >
                        <option value="">{t('queries.all_connections')}</option>
                        {connections.map((c) => (
                            <option key={c} value={c}>
                                {c}
                            </option>
                        ))}
                    </Select>
                    <label className="sr-only" htmlFor="query-sort">
                        {t('queries.sort.label')}
                    </label>
                    <Select
                        id="query-sort"
                        value={filters.sort}
                        onChange={(e) => apply({ sort: e.target.value })}
                    >
                        <option value="total">{t('queries.sort.total')}</option>
                        <option value="p95">{t('queries.sort.p95')}</option>
                        <option value="count">{t('queries.sort.count')}</option>
                    </Select>
                </div>

                <Card className="overflow-x-auto">
                    {stats.rows.length === 0 ? (
                        <EmptyState
                            title={
                                loading
                                    ? t('common.loading')
                                    : t('queries.empty_title')
                            }
                        >
                            {loading ? null : t('queries.empty_body')}
                        </EmptyState>
                    ) : (
                        <table className={tableClass}>
                            <thead>
                                <tr>
                                    <th className={thClass}>
                                        {t('queries.columns.sql')}
                                    </th>
                                    <th className={thClass}>
                                        {t('queries.columns.connection')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('queries.columns.calls')}
                                    </th>
                                    <th className={thClass}>
                                        {t('queries.columns.share')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        p95
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('queries.columns.max')}
                                    </th>
                                    <th className={thClass}>
                                        {t(
                                            filters.period === '7d' ||
                                                filters.period === '30d'
                                                ? 'queries.columns.most_from_day'
                                                : 'queries.columns.most_from',
                                        )}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {stats.rows.map((q) => (
                                    <tr
                                        key={q.sql_hash}
                                        className="hover:bg-panel-2/60"
                                    >
                                        <td
                                            className={`${tdClass} max-w-[520px] min-w-[360px]`}
                                        >
                                            <HighlightedCode
                                                code={q.sql}
                                                language="sql"
                                                title={q.sql}
                                                className="line-clamp-2 font-mono text-xs break-all text-ink"
                                            />
                                            {(q.location || q.n_plus_one) && (
                                                <span className="mt-1 flex flex-wrap items-center gap-2 text-[11px]">
                                                    {q.n_plus_one && (
                                                        <Badge
                                                            tone="warn"
                                                            title={t(
                                                                'queries.n_plus_one_hint',
                                                                {
                                                                    count: q.per_trace,
                                                                },
                                                            )}
                                                        >
                                                            {t(
                                                                'queries.n_plus_one',
                                                                {
                                                                    count: q.per_trace,
                                                                },
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {q.location && (
                                                        <span className="font-mono break-all text-ink-3">
                                                            {q.location}
                                                        </span>
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className={tdClass}>
                                            <Badge>{q.connection}</Badge>
                                        </td>
                                        <td
                                            className={`${tdClass} ${numClass}`}
                                        >
                                            {formatNumber(q.calls)}
                                        </td>
                                        <td
                                            className={`${tdClass} min-w-[140px]`}
                                        >
                                            <div className="flex items-center gap-2">
                                                <div
                                                    aria-hidden="true"
                                                    className="h-1.5 flex-1 overflow-hidden rounded-full bg-line"
                                                >
                                                    <div
                                                        className="h-full bg-info"
                                                        style={{
                                                            width: `${Math.min(100, q.share * 100)}%`,
                                                        }}
                                                    />
                                                </div>
                                                <span className="w-12 text-right font-mono text-xs">
                                                    {formatPercent(q.share)}
                                                </span>
                                            </div>
                                        </td>
                                        <td
                                            className={`${tdClass} ${numClass}`}
                                        >
                                            {formatDuration(q.p95)}
                                        </td>
                                        <td
                                            className={`${tdClass} ${numClass}`}
                                        >
                                            {formatDuration(q.max)}
                                        </td>
                                        <td
                                            className={`${tdClass} font-mono text-xs whitespace-nowrap text-ink-2`}
                                        >
                                            {q.most_from ?? '-'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>
            </PageBody>
        </>
    );
}
