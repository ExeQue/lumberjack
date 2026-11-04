import { Head, Link, router, usePage } from '@inertiajs/react';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Card,
    CardHeader,
    EmptyState,
    PageTitle,
    Segmented,
    Select,
    StatCard,
    TimeBars,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatCompact,
    formatDuration,
    formatNumber,
    formatPercent,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import { index as browserIndex } from '@/routes/browser';
import { show as issueShow, index as issuesIndex } from '@/routes/issues';
import {
    settings as projectSettings,
    show as projectShow,
} from '@/routes/projects';
import type {
    DashboardFilters,
    ProjectHeader,
    TeamShared,
    WebVitalStat,
} from '@/types/lumberjack';

type Device = 'mobile' | 'tablet' | 'desktop';

type Filters = DashboardFilters & {
    device: Device | null;
    sort: 'views' | 'lcp';
};

type Vitals = {
    views: number;
    lcp_us: number | null;
    inp_us: number | null;
    cls: number | null;
};

type Props = {
    project: ProjectHeader;
    filters: Filters;
    /** Whether the project ever received a page view's vitals. */
    has_vitals: boolean;
    summary: Vitals & {
        good: { lcp: number | null; inp: number | null; cls: number | null };
        previous: Vitals;
    };
    devices: { device: Device; views: number; lcp_us: number | null }[];
    series: {
        t: string;
        lcp_us: number | null;
        inp_us: number | null;
        cls: number | null;
    }[];
    pages: WebVitalStat[];
    errors: {
        occurrences: number;
        issues: {
            id: string;
            title: string;
            message: string | null;
            occurrences: number;
        }[];
    };
};

/** Google's good / poor thresholds: LCP and INP in µs, CLS unitless. */
const THRESHOLDS = {
    lcp: [2_500_000, 4_000_000],
    inp: [200_000, 500_000],
    cls: [0.1, 0.25],
} as const;

type Vital = keyof typeof THRESHOLDS;

function rating(
    value: number | null,
    vital: Vital,
): 'ok' | 'warn' | 'err' | undefined {
    if (value === null) {
        return undefined;
    }

    const [good, poor] = THRESHOLDS[vital];

    return value <= good ? 'ok' : value <= poor ? 'warn' : 'err';
}

const RATING_CLASSES = { ok: 'text-ok', warn: 'text-warn', err: 'text-err' };

const ratingClass = (value: number | null, vital: Vital) => {
    const rated = rating(value, vital);

    return rated === undefined ? 'text-ink-3' : RATING_CLASSES[rated];
};

const formatCls = (cls: number | null) => (cls === null ? '-' : cls.toFixed(2));

const DEVICES: Device[] = ['mobile', 'tablet', 'desktop'];

export default function BrowserIndex({
    project,
    filters,
    has_vitals,
    summary,
    devices,
    series,
    pages,
    errors,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;

    useProjectLive(
        project.uuid,
        ['performance.changed', 'issues.changed'],
        ['has_vitals', 'summary', 'devices', 'series', 'pages', 'errors'],
    );

    const apply = (changes: Partial<Filters>) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        ) as Record<string, string>;

        router.get(
            browserIndex(project.uuid, { query }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`${t('browser.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('browser.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody wide>
                <PageTitle
                    title={t('browser.title')}
                    description={t(
                        'browser.description',
                        { count: formatNumber(summary.views) },
                        summary.views,
                    )}
                    actions={
                        has_vitals && (
                            <Segmented<Device | 'all'>
                                label={t('browser.device.label')}
                                value={filters.device ?? 'all'}
                                onChange={(device) =>
                                    apply({
                                        device:
                                            device === 'all' ? null : device,
                                    })
                                }
                                options={(['all', ...DEVICES] as const).map(
                                    (device) => ({
                                        value: device,
                                        label: t(`browser.device.${device}`),
                                    }),
                                )}
                            />
                        )
                    }
                />

                {has_vitals ? (
                    <>
                        <Summary summary={summary} />
                        <Devices devices={devices} selected={filters.device} />
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,360px),1fr))] gap-4">
                            {(['lcp', 'inp', 'cls'] as const).map((vital) => (
                                <VitalChart
                                    key={vital}
                                    vital={vital}
                                    series={series}
                                    period={filters.period}
                                />
                            ))}
                        </div>
                        <Pages
                            pages={pages}
                            sort={filters.sort}
                            onSort={(sort) => apply({ sort })}
                        />
                    </>
                ) : (
                    <Card>
                        <EmptyState title={t('browser.empty.title')}>
                            <p>{t('browser.empty.body')}</p>
                            {project.can_administer && (
                                <Link
                                    href={
                                        projectSettings(project.uuid, {
                                            query: { section: 'install' },
                                        }).url
                                    }
                                    className="mt-2 inline-block text-amber hover:underline"
                                >
                                    {t('browser.empty.link')}
                                </Link>
                            )}
                        </EmptyState>
                    </Card>
                )}

                <Errors project={project} errors={errors} />
            </PageBody>
        </>
    );
}

/** "12 % more than the period before", or nothing without an earlier value. */
function useTrend() {
    const t = useT();

    return (current: number | null, previous: number | null): string => {
        if (current === null || previous === null || previous === 0) {
            return '';
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

function Summary({ summary }: { summary: Props['summary'] }) {
    const t = useT();
    const trend = useTrend();
    const note = (
        vital: Vital,
        current: number | null,
        previous: number | null,
    ) =>
        [
            summary.good[vital] === null
                ? ''
                : t('browser.cards.good', {
                      percent: formatPercent(summary.good[vital], 0),
                  }),
            trend(current, previous),
        ]
            .filter((n) => n !== '')
            .join(' · ');

    return (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,200px),1fr))] gap-4">
            <StatCard
                label={t('browser.cards.views')}
                value={formatCompact(summary.views)}
                note={trend(summary.views, summary.previous.views)}
            />
            <StatCard
                label={t('browser.cards.lcp')}
                value={formatDuration(summary.lcp_us)}
                tone={rating(summary.lcp_us, 'lcp')}
                note={note('lcp', summary.lcp_us, summary.previous.lcp_us)}
            />
            <StatCard
                label={t('browser.cards.inp')}
                value={formatDuration(summary.inp_us)}
                tone={rating(summary.inp_us, 'inp')}
                note={note('inp', summary.inp_us, summary.previous.inp_us)}
            />
            <StatCard
                label={t('browser.cards.cls')}
                value={formatCls(summary.cls)}
                tone={rating(summary.cls, 'cls')}
                note={note('cls', summary.cls, summary.previous.cls)}
            />
        </div>
    );
}

function Devices({
    devices,
    selected,
}: {
    devices: Props['devices'];
    selected: Device | null;
}) {
    const t = useT();

    return (
        <Card aria-labelledby="browser-devices">
            <CardHeader
                id="browser-devices"
                title={t('browser.devices.title')}
                description={t('browser.devices.description')}
            />
            <dl className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,180px),1fr))]">
                {devices.map((d) => (
                    <div
                        key={d.device}
                        className={cn(
                            'flex flex-col gap-1 border-line px-4 py-3 not-last:border-r',
                            selected === d.device && 'bg-panel-2',
                        )}
                    >
                        <dt className="text-xs text-ink-2">
                            {t(`browser.device.${d.device}`)}
                        </dt>
                        <dd
                            className={cn(
                                'font-mono text-lg',
                                ratingClass(d.lcp_us, 'lcp'),
                            )}
                        >
                            {formatDuration(d.lcp_us)}
                        </dd>
                        <dd className="text-xs text-ink-3">
                            {t(
                                'browser.devices.views',
                                { count: formatNumber(d.views) },
                                d.views,
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}

const CHART_KEYS = { lcp: 'lcp_us', inp: 'inp_us', cls: 'cls' } as const;

function VitalChart({
    vital,
    series,
    period,
}: {
    vital: Vital;
    series: Props['series'];
    period: string;
}) {
    const t = useT();
    // The bars scale to at least 1, so CLS is charted in thousandths.
    const scale = vital === 'cls' ? 1000 : 1;
    const format = (v: number) =>
        vital === 'cls' ? (v / scale).toFixed(2) : formatDuration(v);
    // ponytail: a bucket without page views is drawn as an empty bar, not a gap
    const points = series.map((p) => ({
        t: p.t,
        v: (p[CHART_KEYS[vital]] ?? 0) * scale,
    }));
    const max = Math.max(0, ...points.map((p) => p.v));
    const id = `browser-chart-${vital}`;

    return (
        <Card aria-labelledby={id}>
            <CardHeader id={id} title={t(`browser.charts.${vital}`)} />
            <div className="p-4">
                <TimeBars
                    series={points}
                    period={period}
                    color="var(--lj-info)"
                    height={100}
                    formatValue={format}
                    label={t('browser.charts.summary', {
                        vital: t(`browser.pages.${vital}`),
                        max: format(max),
                    })}
                />
            </div>
        </Card>
    );
}

function Pages({
    pages,
    sort,
    onSort,
}: {
    pages: WebVitalStat[];
    sort: Filters['sort'];
    onSort: (sort: Filters['sort']) => void;
}) {
    const t = useT();
    const header = (key: string, help?: string) => (
        <th className={`${thClass} text-right`} title={help}>
            {t(`browser.pages.${key}`)}
        </th>
    );

    return (
        <Card className="overflow-x-auto" aria-labelledby="browser-pages">
            <CardHeader
                id="browser-pages"
                title={t('browser.pages.title')}
                description={t('browser.pages.description')}
                aside={
                    <>
                        <label className="sr-only" htmlFor="browser-sort">
                            {t('browser.pages.sort.label')}
                        </label>
                        <Select
                            id="browser-sort"
                            value={sort}
                            onChange={(e) =>
                                onSort(e.target.value as Filters['sort'])
                            }
                        >
                            <option value="views">
                                {t('browser.pages.sort.views')}
                            </option>
                            <option value="lcp">
                                {t('browser.pages.sort.lcp')}
                            </option>
                        </Select>
                    </>
                }
            />
            {pages.length === 0 ? (
                <EmptyState title={t('browser.pages.empty')} />
            ) : (
                <table className={tableClass}>
                    <thead>
                        <tr>
                            <th className={thClass}>
                                {t('browser.pages.page')}
                            </th>
                            {header('views')}
                            {header('lcp', t('browser.pages.lcp_help'))}
                            {header('inp', t('browser.pages.inp_help'))}
                            {header('cls', t('browser.pages.cls_help'))}
                            {header('ttfb', t('browser.pages.ttfb_help'))}
                            {header('fcp', t('browser.pages.fcp_help'))}
                        </tr>
                    </thead>
                    <tbody>
                        {pages.map((p) => (
                            <tr
                                key={p.route}
                                className="lj-row hover:bg-panel-2/60"
                            >
                                <td
                                    className={`${tdClass} min-w-[280px] font-mono text-xs`}
                                >
                                    {p.route}
                                </td>
                                <td className={`${tdClass} ${numClass}`}>
                                    {formatNumber(p.views)}
                                </td>
                                <td
                                    className={cn(
                                        tdClass,
                                        numClass,
                                        ratingClass(p.lcp_us, 'lcp'),
                                    )}
                                >
                                    {formatDuration(p.lcp_us)}
                                </td>
                                <td
                                    className={cn(
                                        tdClass,
                                        numClass,
                                        ratingClass(p.inp_us, 'inp'),
                                    )}
                                >
                                    {formatDuration(p.inp_us)}
                                </td>
                                <td
                                    className={cn(
                                        tdClass,
                                        numClass,
                                        ratingClass(p.cls, 'cls'),
                                    )}
                                >
                                    {formatCls(p.cls)}
                                </td>
                                <td
                                    className={`${tdClass} ${numClass} text-ink-2`}
                                >
                                    {formatDuration(p.ttfb_us)}
                                </td>
                                <td
                                    className={`${tdClass} ${numClass} text-ink-2`}
                                >
                                    {formatDuration(p.fcp_us)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Card>
    );
}

function Errors({
    project,
    errors,
}: {
    project: ProjectHeader;
    errors: Props['errors'];
}) {
    const t = useT();

    return (
        <Card aria-labelledby="browser-errors">
            <CardHeader
                id="browser-errors"
                title={t('browser.errors.title')}
                description={t(
                    'browser.errors.description',
                    { count: formatNumber(errors.occurrences) },
                    errors.occurrences,
                )}
                aside={
                    <Link
                        href={
                            issuesIndex(project.uuid, {
                                query: { source: 'browser' },
                            }).url
                        }
                        className="text-xs text-amber hover:underline"
                    >
                        {t('browser.errors.all')}
                    </Link>
                }
            />
            {errors.issues.length > 0 && (
                <ul>
                    {errors.issues.map((issue) => (
                        <li
                            key={issue.id}
                            className="border-b border-line/60 last:border-b-0"
                        >
                            <Link
                                href={issueShow([project.uuid, issue.id]).url}
                                className="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-panel-2/60"
                            >
                                <span className="flex min-w-0 flex-col gap-0.5">
                                    <span className="truncate font-mono text-xs">
                                        {issue.title}
                                    </span>
                                    {issue.message && (
                                        <span className="truncate text-xs text-ink-3">
                                            {issue.message}
                                        </span>
                                    )}
                                </span>
                                <span className="shrink-0 font-mono text-xs text-ink-2">
                                    {t(
                                        'browser.errors.occurrences',
                                        {
                                            count: formatNumber(
                                                issue.occurrences,
                                            ),
                                        },
                                        issue.occurrences,
                                    )}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
