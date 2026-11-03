import { Head, Link, usePage } from '@inertiajs/react';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    bucketLabel,
    TimeBars,
    Card,
    CardHeader,
    EmptyState,
    PageTitle,
    StatCard,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatCompact,
    formatDateTime,
    formatDuration,
    formatNumber,
    formatPercent,
    formatRelative,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { useProjectLive } from '@/lib/use-project-live';
import { show as issueShow, index as issuesIndex } from '@/routes/issues';
import { index as jobsIndex } from '@/routes/jobs';
import { index as routesIndex } from '@/routes/routes';
import type {
    DashboardFilters,
    IssueSummary,
    Period,
    ProjectHeader,
    RouteStat,
    SeriesPoint,
    TeamShared,
} from '@/types/lumberjack';

type FailedJobRun = {
    name: string;
    status: 'failed' | 'stuck' | 'released';
    attempt: number;
    max_tries: number | null;
    at: string | null;
};

type Previous = {
    requests: number;
    errors: number;
    p95: number;
    failed_jobs: number;
};

type Overview = {
    previous: Previous;
    requests: number;
    errors: number;
    error_rate: number;
    p50: number;
    p95: number;
    p99: number;
    job_runs: number;
    failed_jobs: number;
    stuck_jobs: number;
    open_issues: number;
    request_series: SeriesPoint[];
    latency_series: LatencyPoint[];
    error_series: SeriesPoint[];
    latest_issues: IssueSummary[];
    slowest_routes: RouteStat[];
    failed_job_runs: FailedJobRun[];
};

type Props = {
    project: ProjectHeader;
    filters: DashboardFilters;
    overview: Overview;
};

const jobTone: Record<FailedJobRun['status'], Tone> = {
    failed: 'err',
    stuck: 'warn',
    released: 'info',
};

// One height for every row, so lists of the same length line up side by side.
const rowClass =
    'flex min-h-14 items-center justify-between gap-3 px-4 py-2.5 text-ink';
const itemClass = 'border-b border-line/60 last:border-b-0';

type LatencyPoint = { t: string; p50: number | null; p95: number | null };

/**
 * p50 and p95 as two lines; a bucket without requests breaks the line.
 */
function LatencyChart({
    series,
    period,
}: {
    series: LatencyPoint[];
    period: Period;
}) {
    const t = useT();
    const width = 800;
    const height = 140;
    const max = Math.max(1, ...series.map((p) => p.p95 ?? 0));
    const x = (i: number) => (i / Math.max(1, series.length - 1)) * width;
    const y = (v: number) => height - 4 - (v / max) * (height - 8);
    const segments = (key: 'p50' | 'p95') =>
        series
            .reduce<string[][]>(
                (lines, p, i) => {
                    const v = p[key];

                    if (v === null) {
                        lines.push([]);
                    } else {
                        lines[lines.length - 1].push(`${x(i)},${y(v)}`);
                    }

                    return lines;
                },
                [[]],
            )
            .filter((line) => line.length > 0);
    const p95s = series.flatMap((p) => (p.p95 === null ? [] : [p.p95]));
    const p50s = series.flatMap((p) => (p.p50 === null ? [] : [p.p50]));
    const summary =
        p95s.length > 0
            ? t('overview.charts.latency_summary', {
                  p95: formatDuration(Math.max(...p95s)),
                  p50: formatDuration(Math.max(...p50s)),
              })
            : t('overview.charts.no_data');
    const ticks =
        series.length > 1
            ? [0, 0.25, 0.5, 0.75, 1].map(
                  (f) => series[Math.round(f * (series.length - 1))],
              )
            : series;

    return (
        <Card aria-labelledby="chart-latency">
            <CardHeader
                id="chart-latency"
                title={t('overview.charts.latency')}
                aside={
                    <span className="flex items-center gap-3 text-xs text-ink-3">
                        <span className="flex items-center gap-1.5">
                            <span className="h-0.5 w-3 rounded bg-amber" />
                            p95
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="h-0.5 w-3 rounded bg-ink-3" />
                            p50
                        </span>
                    </span>
                }
            />
            <div className="flex flex-col gap-1.5 p-4">
                <div className="flex gap-2">
                    <div
                        className="flex w-14 shrink-0 flex-col justify-between text-right font-mono text-[11px] text-ink-3"
                        aria-hidden="true"
                    >
                        <span>{formatDuration(max)}</span>
                        <span>0</span>
                    </div>
                    <div className="relative min-w-0 flex-1">
                        <svg
                            viewBox={`0 0 ${width} ${height}`}
                            preserveAspectRatio="none"
                            className="block w-full"
                            style={{ height }}
                            role="img"
                            aria-label={summary}
                        >
                            {segments('p50').map((points, i) => (
                                <polyline
                                    key={`p50-${i}`}
                                    points={points.join(' ')}
                                    fill="none"
                                    stroke="var(--lj-ink-3)"
                                    strokeWidth={1.5}
                                    vectorEffect="non-scaling-stroke"
                                />
                            ))}
                            {segments('p95').map((points, i) => (
                                <polyline
                                    key={`p95-${i}`}
                                    points={points.join(' ')}
                                    fill="none"
                                    stroke="var(--lj-amber)"
                                    strokeWidth={2}
                                    vectorEffect="non-scaling-stroke"
                                />
                            ))}
                        </svg>
                        {/* Hover: a guide line and the bucket's values, one column per point. */}
                        <div className="absolute inset-0" aria-hidden="true">
                            {series.map((p, i) => {
                                const step =
                                    100 / Math.max(1, series.length - 1);

                                return (
                                    <div
                                        key={p.t}
                                        className="group absolute inset-y-0 -translate-x-1/2"
                                        style={{
                                            left: `${i * step}%`,
                                            width: `${step}%`,
                                        }}
                                    >
                                        <div className="absolute inset-y-0 left-1/2 hidden w-px bg-line-strong group-hover:block" />
                                        {/* A point without neighbors draws no line, so it gets a dot. */}
                                        {(['p50', 'p95'] as const).map(
                                            (key) =>
                                                p[key] !== null &&
                                                (series[i - 1]?.[key] ??
                                                    null) === null &&
                                                (series[i + 1]?.[key] ??
                                                    null) === null && (
                                                    <span
                                                        key={key}
                                                        className={`absolute left-1/2 size-1.5 -translate-1/2 rounded-full ${key === 'p95' ? 'bg-amber' : 'bg-ink-3'}`}
                                                        style={{
                                                            top: `${(y(p[key]) / height) * 100}%`,
                                                        }}
                                                    />
                                                ),
                                        )}
                                        <span className="pointer-events-none absolute top-0 left-1/2 z-10 hidden -translate-x-1/2 -translate-y-full rounded border border-line-strong bg-panel-2 px-2 py-1 font-mono text-[11px] whitespace-nowrap text-ink shadow-lg group-hover:block">
                                            {bucketLabel(p.t, period)}
                                            {p.p95 === null
                                                ? ' · -'
                                                : ` · p95 ${formatDuration(p.p95)} · p50 ${formatDuration(p.p50)}`}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
                <div
                    className="ml-16 flex justify-between font-mono text-[11px] text-ink-3"
                    aria-hidden="true"
                >
                    {ticks.map((p, i) => (
                        <span key={i}>{bucketLabel(p.t, period)}</span>
                    ))}
                </div>
                <p className="text-xs text-ink-3">{summary}</p>
            </div>
        </Card>
    );
}

function SeriesChart({
    id,
    title,
    series,
    period,
    color,
    unit,
}: {
    id: string;
    title: string;
    series: SeriesPoint[];
    period: Period;
    color: string;
    unit: string;
}) {
    const t = useT();
    const values = series.map((p) => p.v);
    const total = values.reduce((a, b) => a + b, 0);
    const peak = series.reduce<SeriesPoint | null>(
        (best, p) => (best === null || p.v > best.v ? p : best),
        null,
    );
    const peakText =
        peak && peak.v > 0
            ? t('overview.charts.peak', {
                  value: formatNumber(peak.v),
                  time: bucketLabel(peak.t, period),
              })
            : t('overview.charts.no_data');
    return (
        <Card aria-labelledby={id}>
            <CardHeader
                id={id}
                title={title}
                aside={<span className="text-xs text-ink-3">{peakText}</span>}
            />
            <div className="p-4">
                <TimeBars
                    series={series}
                    period={period}
                    color={color}
                    formatValue={formatNumber}
                    label={t('overview.charts.summary', {
                        total: formatNumber(total),
                        unit,
                        peak: peakText,
                    })}
                />
            </div>
        </Card>
    );
}

/**
 * "12 % more than the period before", or nothing when there is no earlier data to compare with.
 */
function useTrend() {
    const t = useT();

    return (current: number, previous: number): string => {
        if (previous === 0) {
            return current === 0 ? '' : t('overview.trend.new');
        }

        const change = (current - previous) / previous;

        if (Math.abs(change) < 0.005) {
            return t('overview.trend.same');
        }

        return t(change > 0 ? 'overview.trend.up' : 'overview.trend.down', {
            percent: formatPercent(Math.abs(change), 0),
        });
    };
}

function joinNotes(...notes: string[]): string {
    return notes.filter((n) => n !== '').join(' · ');
}

function SectionLink({ href, children }: { href: string; children: string }) {
    return (
        <Link href={href} className="text-xs text-amber hover:underline">
            {children}
        </Link>
    );
}

export default function ProjectOverview({ project, filters, overview }: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;

    useProjectLive(
        project.id,
        [
            'issues.changed',
            'issue.updated',
            'jobs.changed',
            'performance.changed',
        ],
        ['overview'],
    );

    const failureRate =
        overview.job_runs > 0 ? overview.failed_jobs / overview.job_runs : 0;
    const trend = useTrend();
    const previous = overview.previous;

    return (
        <>
            <Head title={`${t('overview.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    { label: project.name, mono: true },
                    { label: t('overview.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody wide>
                <PageTitle title={t('overview.title')} />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
                    <StatCard
                        label={t('overview.kpis.requests')}
                        value={formatCompact(overview.requests)}
                        note={joinNotes(
                            t('overview.kpis.requests_note', {
                                count: formatNumber(overview.requests),
                            }),
                            trend(overview.requests, previous.requests),
                        )}
                    />
                    <StatCard
                        label={t('overview.kpis.error_rate')}
                        value={formatPercent(overview.error_rate, 2)}
                        tone={overview.error_rate > 0 ? 'err' : undefined}
                        note={joinNotes(
                            t(
                                'overview.kpis.error_rate_note',
                                {},
                                overview.errors,
                            ),
                            trend(overview.errors, previous.errors),
                        )}
                    />
                    <StatCard
                        label={t('overview.kpis.p95')}
                        value={
                            overview.requests > 0
                                ? formatDuration(overview.p95)
                                : '-'
                        }
                        note={
                            overview.requests > 0
                                ? joinNotes(
                                      t('overview.kpis.p95_note', {
                                          p50: formatDuration(overview.p50),
                                          p99: formatDuration(overview.p99),
                                      }),
                                      trend(overview.p95, previous.p95),
                                  )
                                : t('overview.kpis.p95_none')
                        }
                    />
                    <StatCard
                        label={t('overview.kpis.failed_jobs')}
                        value={formatNumber(overview.failed_jobs)}
                        tone={overview.failed_jobs > 0 ? 'warn' : undefined}
                        note={joinNotes(
                            t(
                                'overview.kpis.failed_jobs_note',
                                {
                                    rate: formatPercent(failureRate),
                                    stuck: formatNumber(overview.stuck_jobs),
                                },
                                overview.job_runs,
                            ),
                            trend(overview.failed_jobs, previous.failed_jobs),
                        )}
                    />
                    <StatCard
                        label={t('overview.kpis.open_issues')}
                        value={formatNumber(overview.open_issues)}
                        tone={overview.open_issues > 0 ? 'err' : undefined}
                        note={t('overview.kpis.open_issues_note')}
                    />
                </div>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,420px),1fr))] gap-4">
                    <SeriesChart
                        id="chart-requests"
                        title={t('overview.charts.requests')}
                        unit={t('overview.charts.requests_unit')}
                        series={overview.request_series}
                        period={filters.period}
                        color="var(--lj-line-strong)"
                    />
                    <SeriesChart
                        id="chart-errors"
                        title={t('overview.charts.errors')}
                        unit={t('overview.charts.errors_unit')}
                        series={overview.error_series}
                        period={filters.period}
                        color="var(--lj-err)"
                    />
                </div>

                <LatencyChart
                    series={overview.latency_series}
                    period={filters.period}
                />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,360px),1fr))] items-start gap-4">
                    <Card aria-labelledby="latest-issues">
                        <CardHeader
                            id="latest-issues"
                            title={t('overview.latest_issues.title')}
                            aside={
                                <SectionLink
                                    href={issuesIndex(project.uuid).url}
                                >
                                    {t('overview.latest_issues.all')}
                                </SectionLink>
                            }
                        />
                        {overview.latest_issues.length === 0 ? (
                            <EmptyState
                                title={t('overview.latest_issues.empty')}
                            />
                        ) : (
                            <ul>
                                {overview.latest_issues.map((issue) => (
                                    <li key={issue.id} className={itemClass}>
                                        <Link
                                            href={
                                                issueShow([
                                                    project.uuid,
                                                    issue.id,
                                                ]).url
                                            }
                                            className={`${rowClass} hover:bg-panel-2/60`}
                                        >
                                            <span className="flex min-w-0 flex-col gap-0.5">
                                                <span className="truncate font-mono text-xs">
                                                    {issue.title}
                                                </span>
                                                <span className="truncate text-xs text-ink-3">
                                                    {issue.message}
                                                </span>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-2">
                                                {issue.regressed ? (
                                                    <Badge tone="err">
                                                        {t(
                                                            'common.flags.regression',
                                                        )}
                                                    </Badge>
                                                ) : issue.new ? (
                                                    <Badge tone="info">
                                                        {t('common.flags.new')}
                                                    </Badge>
                                                ) : (
                                                    <Badge>
                                                        {t(
                                                            `common.sources.${issue.source}`,
                                                        )}
                                                    </Badge>
                                                )}
                                                <span className="font-mono text-xs text-ink-2">
                                                    <span aria-hidden="true">
                                                        {formatNumber(
                                                            issue.occurrences,
                                                        )}
                                                    </span>
                                                    <span className="sr-only">
                                                        {t(
                                                            'overview.latest_issues.occurrences',
                                                            {
                                                                count: formatNumber(
                                                                    issue.occurrences,
                                                                ),
                                                            },
                                                            issue.occurrences,
                                                        )}
                                                    </span>
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card aria-labelledby="slowest-routes">
                        <CardHeader
                            id="slowest-routes"
                            title={t('overview.slowest_routes.title')}
                            aside={
                                <SectionLink
                                    href={routesIndex(project.uuid).url}
                                >
                                    {t('overview.slowest_routes.all')}
                                </SectionLink>
                            }
                        />
                        {overview.slowest_routes.length === 0 ? (
                            <EmptyState
                                title={t('overview.slowest_routes.empty')}
                            />
                        ) : (
                            <ul>
                                {overview.slowest_routes.map((route) => (
                                    <li
                                        key={`${route.method} ${route.route}`}
                                        className={itemClass}
                                    >
                                        <Link
                                            href={
                                                routesIndex(project.uuid, {
                                                    query: {
                                                        route: route.route,
                                                        method: route.method,
                                                        period: filters.period,
                                                    },
                                                }).url
                                            }
                                            className={`${rowClass} hover:bg-panel-2/60`}
                                        >
                                            <span className="min-w-0 truncate font-mono text-xs">
                                                <span className="text-ink-3">
                                                    {route.method}
                                                </span>{' '}
                                                {route.route}
                                            </span>
                                            <span
                                                className={`shrink-0 font-mono text-xs ${route.p95 >= 1_000_000 ? 'text-err' : route.p95 >= 500_000 ? 'text-warn' : ''}`}
                                            >
                                                <span className="sr-only">
                                                    p95{' '}
                                                </span>
                                                {formatDuration(route.p95)}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card aria-labelledby="failed-jobs">
                        <CardHeader
                            id="failed-jobs"
                            title={t('overview.failed_jobs.title')}
                            aside={
                                <SectionLink href={jobsIndex(project.uuid).url}>
                                    {t('overview.failed_jobs.all')}
                                </SectionLink>
                            }
                        />
                        {overview.failed_job_runs.length === 0 ? (
                            <EmptyState
                                title={t('overview.failed_jobs.empty')}
                            />
                        ) : (
                            <ul>
                                {overview.failed_job_runs.map((run, i) => (
                                    <li key={i} className={itemClass}>
                                        <Link
                                            href={
                                                jobsIndex(project.uuid, {
                                                    query: {
                                                        job: run.name,
                                                        period: filters.period,
                                                    },
                                                }).url
                                            }
                                            className={`${rowClass} hover:bg-panel-2/60`}
                                        >
                                            <span className="flex min-w-0 flex-col gap-0.5">
                                                <span className="truncate font-mono text-xs">
                                                    {run.name}
                                                </span>
                                                <span className="text-xs text-ink-3">
                                                    {run.max_tries
                                                        ? t(
                                                              'overview.failed_jobs.attempt_of',
                                                              {
                                                                  attempt:
                                                                      run.attempt,
                                                                  max: run.max_tries,
                                                              },
                                                          )
                                                        : t(
                                                              'overview.failed_jobs.attempt',
                                                              {
                                                                  attempt:
                                                                      run.attempt,
                                                              },
                                                          )}
                                                    {', '}
                                                    <time
                                                        dateTime={
                                                            run.at ?? undefined
                                                        }
                                                        title={formatDateTime(
                                                            run.at,
                                                        )}
                                                    >
                                                        {formatRelative(run.at)}
                                                    </time>
                                                </span>
                                            </span>
                                            <Badge tone={jobTone[run.status]}>
                                                {t(
                                                    `common.job_statuses.${run.status}`,
                                                )}
                                            </Badge>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </PageBody>
        </>
    );
}
