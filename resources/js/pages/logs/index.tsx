import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { ArrowUp } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    Card,
    EmptyState,
    Eyebrow,
    Input,
    PageTitle,
    Segmented,
    tableClass,
    thClass,
    Select,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatDateTime,
    formatNumber,
    formatTime,
    interpolateLogMessage,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as logsIndex } from '@/routes/logs';
import { show as traceShow } from '@/routes/traces';
import {
    settings as projectSettings,
    show as projectShow,
} from '@/routes/projects';
import type {
    DashboardFilters,
    LogLine,
    Period,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Filters = DashboardFilters & {
    channel: string;
    level: string;
    search: string;
    release: string;
};

type ChannelCount = { name: string; count: number };

type Props = {
    project: ProjectHeader;
    filters: Filters;
    releases: string[];
    channels: ChannelCount[];
    unknown_channels: ChannelCount[];
    lines: LogLine[];
    limit: number;
};

const levels = [
    'debug',
    'info',
    'notice',
    'warning',
    'error',
    'critical',
] as const;

const periods: Period[] = ['1h', '24h', '7d', '30d'];

const levelTone: Record<LogLine['level'], Tone> = {
    debug: 'neutral',
    info: 'info',
    notice: 'info',
    warning: 'warn',
    error: 'err',
    critical: 'err',
    alert: 'err',
    emergency: 'err',
};

const live = ['lines', 'channels', 'unknown_channels'];

export default function LogsIndex({
    project,
    filters,
    channels,
    unknown_channels,
    lines,
    limit,
    releases,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [search, setSearch] = useState(filters.search);
    const [pending, setPending] = useState(0);

    const apply = (changes: Partial<Filters>) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        ) as Record<string, string>;

        setPending(0);
        router.get(
            logsIndex(project.uuid, { query }).url,
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

    // Live tail: reload in place while the reader sits at the top; hold back while they scroll or select text.
    useEcho(
        `project.${project.uuid}`,
        ['.logs.changed'],
        () => {
            if (
                window.scrollY > 100 ||
                (window.getSelection()?.toString() ?? '') !== ''
            ) {
                setPending((n) => n + 1);

                return;
            }

            router.reload({ only: live, preserveUrl: true } as Parameters<
                typeof router.reload
            >[0]);
        },
        [project.uuid],
    );

    const showNew = () => {
        router.reload({
            only: live,
            preserveUrl: true,
            onSuccess: () => {
                setPending(0);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },
        } as Parameters<typeof router.reload>[0]);
    };

    const widerPeriod = periods[periods.indexOf(filters.period) + 1];
    const total = channels.reduce((sum, c) => sum + c.count, 0);
    const channelOptions = [
        { value: '', label: t('logs.all_channels'), count: total },
        ...channels.map((c) => ({
            value: c.name,
            label: c.name,
            count: c.count,
        })),
    ];

    return (
        <>
            <Head title={`${t('logs.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('logs.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody>
                <PageTitle title={t('logs.title')} />

                <div className="flex flex-wrap items-start gap-4">
                    <Card className="flex flex-[1_1_220px] flex-col gap-0.5 p-2">
                        <div
                            role="group"
                            aria-label={t('logs.channels')}
                            className="flex flex-col gap-0.5"
                        >
                            <Eyebrow className="px-2.5 py-1.5">
                                {t('logs.channels')}
                            </Eyebrow>
                            {channelOptions.map((c) => (
                                <button
                                    key={c.value}
                                    type="button"
                                    aria-pressed={filters.channel === c.value}
                                    onClick={() => apply({ channel: c.value })}
                                    className="lj-nav-item relative flex h-[34px] w-full cursor-pointer items-center justify-between gap-2 rounded-md px-2.5 text-left text-ink-2 hover:bg-panel-2 hover:text-ink aria-pressed:rounded-l-none aria-pressed:bg-panel-2 aria-pressed:text-ink aria-pressed:before:absolute aria-pressed:before:inset-y-0 aria-pressed:before:left-0 aria-pressed:before:w-[3px] aria-pressed:before:rounded-full aria-pressed:before:bg-amber"
                                >
                                    <span
                                        className={cn(
                                            'truncate',
                                            c.value !== '' &&
                                                'font-mono text-xs',
                                        )}
                                    >
                                        {c.label}
                                    </span>
                                    <span className="font-mono text-[11px] text-ink-3">
                                        {formatNumber(c.count)}
                                    </span>
                                </button>
                            ))}
                        </div>
                        {project.can_administer && (
                            <Link
                                href={projectSettings(project.uuid).url}
                                className="p-2.5 text-xs text-amber hover:underline"
                            >
                                {t('logs.manage_channels')}
                            </Link>
                        )}
                    </Card>

                    <section
                        aria-label={t('logs.lines')}
                        className="flex min-w-0 flex-[999_1_560px] flex-col gap-3"
                    >
                        {unknown_channels.length > 0 && (
                            <div
                                role="status"
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warn-line bg-warn-bg px-3.5 py-2.5"
                            >
                                <span>
                                    {t(
                                        'logs.unknown_banner',
                                        {
                                            channels: unknown_channels
                                                .map((c) => c.name)
                                                .join(', '),
                                        },
                                        unknown_channels.reduce(
                                            (sum, c) => sum + c.count,
                                            0,
                                        ),
                                    )}
                                </span>
                                {project.can_administer && (
                                    <Link
                                        href={projectSettings(project.uuid).url}
                                        className="text-xs text-amber hover:underline"
                                    >
                                        {t('logs.create_channel')}
                                    </Link>
                                )}
                            </div>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Segmented
                                label={t('logs.min_level')}
                                value={filters.level}
                                onChange={(level) => apply({ level })}
                                options={levels.map((level) => ({
                                    value: level,
                                    label: t(`common.levels.${level}`),
                                }))}
                            />
                            <label className="sr-only" htmlFor="log-search">
                                {t('logs.search')}
                            </label>
                            <Input
                                id="log-search"
                                type="search"
                                placeholder={t('logs.search')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="min-w-44 flex-1"
                            />
                            <label className="sr-only" htmlFor="log-release">
                                {t('logs.release')}
                            </label>
                            <Select
                                id="log-release"
                                value={filters.release}
                                onChange={(e) =>
                                    apply({ release: e.target.value })
                                }
                            >
                                <option value="">
                                    {t('logs.all_releases')}
                                </option>
                                {releases.map((release) => (
                                    <option key={release} value={release}>
                                        {release}
                                    </option>
                                ))}
                            </Select>
                        </div>

                        {pending > 0 && (
                            <div className="sticky top-2 z-10 flex justify-center">
                                <Button variant="primary" onClick={showNew}>
                                    <ArrowUp
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {t('logs.show_new')}
                                </Button>
                            </div>
                        )}

                        <Card className="overflow-x-auto">
                            {lines.length === 0 ? (
                                <EmptyState title={t('logs.empty_title')}>
                                    <p>{t('logs.empty_body')}</p>
                                    {widerPeriod && (
                                        <Button
                                            className="mt-3"
                                            onClick={() =>
                                                apply({ period: widerPeriod })
                                            }
                                        >
                                            {t('logs.widen_period', {
                                                period: t(
                                                    `common.periods.${widerPeriod}`,
                                                ),
                                            })}
                                        </Button>
                                    )}
                                </EmptyState>
                            ) : (
                                <table
                                    className={cn(
                                        tableClass,
                                        'min-w-[760px] font-mono text-xs',
                                    )}
                                >
                                    <thead>
                                        <tr>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'w-28 font-sans',
                                                )}
                                            >
                                                {t('logs.columns.time')}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'w-20 font-sans',
                                                )}
                                            >
                                                {t('logs.columns.level')}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'w-36 font-sans',
                                                )}
                                            >
                                                {t('logs.columns.channel')}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'font-sans',
                                                )}
                                            >
                                                {t('logs.columns.message')}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'w-24 font-sans',
                                                )}
                                            >
                                                {t('common.trace')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lines.map((l) => (
                                            <tr
                                                key={l.id}
                                                className="align-baseline hover:bg-panel-2/60"
                                            >
                                                <td className="border-b border-line/60 px-3 py-2 whitespace-nowrap text-ink-3">
                                                    <time
                                                        dateTime={l.at}
                                                        title={formatDateTime(
                                                            l.at,
                                                        )}
                                                    >
                                                        {formatTime(l.at, true)}
                                                    </time>
                                                </td>
                                                <td className="border-b border-line/60 px-3 py-2">
                                                    <Badge
                                                        tone={
                                                            levelTone[l.level]
                                                        }
                                                        className="w-[68px] justify-center"
                                                    >
                                                        {t(
                                                            `common.levels.${l.level}`,
                                                        )}
                                                    </Badge>
                                                </td>
                                                <td
                                                    className="max-w-36 truncate border-b border-line/60 px-3 py-2 text-ink-2"
                                                    title={
                                                        l.channel ?? undefined
                                                    }
                                                >
                                                    {l.channel ?? '-'}
                                                </td>
                                                <td className="max-w-0 border-b border-line/60 px-3 py-2">
                                                    <div className="flex min-w-0 flex-col gap-0.5">
                                                        <span
                                                            className="break-words whitespace-pre-wrap text-ink"
                                                            title={l.message}
                                                        >
                                                            {interpolateLogMessage(
                                                                l.message,
                                                                l.context,
                                                            )}
                                                        </span>
                                                        {l.sent_channel && (
                                                            <span className="text-warn">
                                                                {t(
                                                                    'logs.sent_to_unknown',
                                                                    {
                                                                        channel:
                                                                            l.sent_channel,
                                                                    },
                                                                )}
                                                            </span>
                                                        )}
                                                        {l.context &&
                                                            Object.keys(
                                                                l.context,
                                                            ).length > 0 && (
                                                                <span className="truncate text-ink-3">
                                                                    {JSON.stringify(
                                                                        l.context,
                                                                    )}
                                                                </span>
                                                            )}
                                                    </div>
                                                </td>
                                                <td
                                                    className="max-w-24 truncate border-b border-line/60 px-3 py-2 text-ink-2"
                                                    title={
                                                        l.trace_id ?? undefined
                                                    }
                                                >
                                                    {l.trace_id && (
                                                        <Link
                                                            href={
                                                                traceShow([
                                                                    project.uuid,
                                                                    l.trace_id,
                                                                ]).url
                                                            }
                                                            className="hover:text-amber hover:underline"
                                                        >
                                                            {l.trace_id}
                                                        </Link>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </Card>

                        {lines.length >= limit && (
                            <p className="text-xs text-ink-3">
                                {t('logs.limit_reached', {
                                    limit: formatNumber(limit),
                                })}
                            </p>
                        )}
                    </section>
                </div>
            </PageBody>
        </>
    );
}
