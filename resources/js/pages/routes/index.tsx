import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Card,
    CardHeader,
    EmptyState,
    Eyebrow,
    Input,
    PageTitle,
    Select,
    numClass,
    tableClass,
    tdClass,
    thClass,
    Sparkline,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatDuration,
    formatNumber,
    formatPercent,
    formatTime,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import { show as projectShow } from '@/routes/projects';
import { index as routesIndex } from '@/routes/routes';
import type {
    DashboardFilters,
    ProjectHeader,
    RouteStat,
    TeamShared,
} from '@/types/lumberjack';

type Filters = DashboardFilters & {
    sort: string;
    search: string;
    route: string;
    method: string;
};

type Detail = {
    method: string;
    route: string;
    hourly: { t: string; p50: number; p95: number }[];
    slowest: {
        at: string;
        host: string | null;
        status: number | null;
        duration_us: number;
        trace_id: string | null;
        queries: number | null;
    }[];
};

type Props = {
    project: ProjectHeader;
    filters: Filters;
    routes: RouteStat[];
    detail: Detail | null;
};

export default function RoutesIndex({
    project,
    filters,
    routes,
    detail,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [search, setSearch] = useState(filters.search);

    useProjectLive(project.id, ['performance.changed'], ['routes', 'detail']);

    const apply = (changes: Partial<Filters>, only?: string[]) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        ) as Record<string, string>;

        router.get(
            routesIndex(project.uuid, { query }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true, only },
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

    const isSelected = (r: RouteStat) =>
        r.route === filters.route && r.method === filters.method;
    const pick = (r: RouteStat) =>
        isSelected(r)
            ? apply({ route: '', method: '' }, ['detail', 'filters'])
            : apply({ route: r.route, method: r.method }, [
                  'detail',
                  'filters',
              ]);
    const selected = routes.find(isSelected);
    const totalRequests = routes.reduce((sum, r) => sum + r.requests, 0);

    return (
        <>
            <Head title={`${t('routes.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('routes.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody wide>
                <PageTitle
                    title={t('routes.title')}
                    description={t(
                        'routes.description',
                        { requests: formatNumber(totalRequests) },
                        routes.length,
                    )}
                    actions={
                        <>
                            <label className="sr-only" htmlFor="route-search">
                                {t('routes.search')}
                            </label>
                            <Input
                                id="route-search"
                                type="search"
                                placeholder={t('routes.search')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-56 max-w-full"
                            />
                            <label className="sr-only" htmlFor="route-sort">
                                {t('routes.sort.label')}
                            </label>
                            <Select
                                id="route-sort"
                                value={filters.sort}
                                onChange={(e) =>
                                    apply({ sort: e.target.value })
                                }
                            >
                                <option value="p95">
                                    {t('routes.sort.p95')}
                                </option>
                                <option value="count">
                                    {t('routes.sort.count')}
                                </option>
                                <option value="errors">
                                    {t('routes.sort.errors')}
                                </option>
                                <option value="total">
                                    {t('routes.sort.total')}
                                </option>
                            </Select>
                        </>
                    }
                />

                <Card className="overflow-x-auto">
                    {routes.length === 0 ? (
                        <EmptyState title={t('routes.empty_title')}>
                            {t('routes.empty_body')}
                        </EmptyState>
                    ) : (
                        <table className={tableClass}>
                            <thead>
                                <tr>
                                    <th className={thClass}>
                                        {t('routes.columns.route')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('routes.columns.requests')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        p50
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        p95
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        p99
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('routes.columns.error_rate')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('routes.columns.total')}
                                    </th>
                                    <th className={thClass}>
                                        {t('routes.columns.trend')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {routes.map((r) => {
                                    const active = isSelected(r);

                                    return (
                                        <tr
                                            key={`${r.method} ${r.route}`}
                                            className={cn(
                                                'hover:bg-panel-2/60',
                                                active && 'bg-panel-2',
                                            )}
                                        >
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'min-w-[280px]',
                                                    active &&
                                                        'shadow-[inset_2px_0_0_var(--lj-amber)]',
                                                )}
                                            >
                                                <button
                                                    type="button"
                                                    aria-pressed={active}
                                                    onClick={() => pick(r)}
                                                    className="cursor-pointer text-left font-mono text-xs text-ink hover:text-amber"
                                                >
                                                    <span className="text-ink-3">
                                                        {r.method}
                                                    </span>{' '}
                                                    {r.route}
                                                </button>
                                            </td>
                                            <td
                                                className={`${tdClass} ${numClass}`}
                                            >
                                                {formatNumber(r.requests)}
                                            </td>
                                            <td
                                                className={`${tdClass} ${numClass}`}
                                            >
                                                {formatDuration(r.p50)}
                                            </td>
                                            <td
                                                className={`${tdClass} ${numClass}`}
                                            >
                                                {formatDuration(r.p95)}
                                            </td>
                                            <td
                                                className={`${tdClass} ${numClass}`}
                                            >
                                                {formatDuration(r.p99)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                    r.error_rate > 0.01
                                                        ? 'text-err'
                                                        : r.error_rate === 0 &&
                                                              'text-ink-3',
                                                )}
                                            >
                                                {formatPercent(r.error_rate, 2)}
                                            </td>
                                            <td
                                                className={`${tdClass} ${numClass} text-ink-2`}
                                            >
                                                {formatDuration(r.total_us)}
                                            </td>
                                            <td className={tdClass}>
                                                <Sparkline
                                                    values={r.series ?? []}
                                                    color="var(--lj-info)"
                                                    label={t(
                                                        'routes.trend_label',
                                                        {
                                                            count: formatNumber(
                                                                r.requests,
                                                            ),
                                                        },
                                                    )}
                                                />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </Card>

                {detail && (
                    <Card aria-labelledby="route-detail">
                        <CardHeader
                            id="route-detail"
                            title={
                                <span className="font-mono">
                                    <span className="text-ink-3">
                                        {detail.method}
                                    </span>{' '}
                                    {detail.route}
                                </span>
                            }
                            aside={
                                selected && (
                                    <span className="text-xs text-ink-3">
                                        {t('routes.detail.summary', {
                                            requests: formatNumber(
                                                selected.requests,
                                            ),
                                            p95: formatDuration(selected.p95),
                                            errors: formatPercent(
                                                selected.error_rate,
                                                2,
                                            ),
                                        })}
                                    </span>
                                )
                            }
                        />
                        <div className="flex flex-wrap">
                            <div className="flex min-w-0 flex-[1_1_420px] flex-col gap-2 p-4">
                                <Eyebrow>{t('routes.detail.hourly')}</Eyebrow>
                                <HourlyChart hourly={detail.hourly} />
                            </div>
                            <div className="flex min-w-0 flex-[1_1_420px] flex-col border-line md:border-l">
                                <Eyebrow className="px-4 pt-4 pb-2">
                                    {t('routes.detail.slowest')}
                                </Eyebrow>
                                {detail.slowest.length === 0 ? (
                                    <p className="px-4 pb-4 text-ink-3">
                                        {t('routes.detail.no_requests')}
                                    </p>
                                ) : (
                                    <ul>
                                        {detail.slowest.map((s, i) => (
                                            <li
                                                key={i}
                                                className="flex flex-wrap justify-between gap-3 border-t border-line/60 px-4 py-2 font-mono text-xs"
                                            >
                                                <span className="text-ink-2">
                                                    <time dateTime={s.at}>
                                                        {formatTime(s.at)}
                                                    </time>
                                                    {s.host && ` · ${s.host}`}
                                                    {s.queries !== null &&
                                                        ` · ${t('routes.detail.queries', {}, s.queries)}`}
                                                    {s.trace_id && (
                                                        <>
                                                            {' · '}
                                                            <span
                                                                title={t(
                                                                    'common.trace',
                                                                )}
                                                            >
                                                                {s.trace_id}
                                                            </span>
                                                        </>
                                                    )}
                                                </span>
                                                <span className="flex items-center gap-2">
                                                    {formatDuration(
                                                        s.duration_us,
                                                    )}
                                                    {s.status !== null && (
                                                        <Badge
                                                            tone={
                                                                s.status >= 500
                                                                    ? 'err'
                                                                    : s.status >=
                                                                        400
                                                                      ? 'warn'
                                                                      : 'neutral'
                                                            }
                                                        >
                                                            {s.status}
                                                        </Badge>
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    </Card>
                )}
            </PageBody>
        </>
    );
}

function HourlyChart({ hourly }: { hourly: Detail['hourly'] }) {
    const t = useT();
    const max = Math.max(1, ...hourly.map((h) => h.p95));
    const peak = hourly.reduce<Detail['hourly'][number] | null>(
        (best, h) => (best === null || h.p95 > best.p95 ? h : best),
        null,
    );
    const label = peak
        ? t('routes.detail.chart_label', {
              p95: formatDuration(peak.p95),
              time: formatTime(peak.t),
          })
        : t('routes.detail.no_requests');

    return (
        <>
            <div
                role="img"
                aria-label={label}
                className="flex h-[120px] items-end gap-[3px]"
            >
                {hourly.map((h) => (
                    <div
                        key={h.t}
                        className="relative flex-1 rounded-t-sm bg-info/45"
                        style={{
                            height: `${Math.max(2, (h.p95 / max) * 100)}%`,
                        }}
                        title={`${formatTime(h.t)}: p50 ${formatDuration(h.p50)}, p95 ${formatDuration(h.p95)}`}
                    >
                        <div
                            className="absolute inset-x-0 bottom-0 bg-info"
                            style={{
                                height: `${h.p95 ? (h.p50 / h.p95) * 100 : 0}%`,
                            }}
                        />
                    </div>
                ))}
            </div>
            <div className="flex gap-4 text-xs text-ink-2">
                <span className="inline-flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="size-2.5 rounded-sm bg-info"
                    />
                    p50
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="size-2.5 rounded-sm bg-info/45"
                    />
                    p95
                </span>
            </div>
        </>
    );
}
