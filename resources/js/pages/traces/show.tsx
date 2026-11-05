import { Head, Link } from '@inertiajs/react';
import { HighlightedCode } from '@/components/lj/highlighted-code';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Card,
    CardHeader,
    EmptyState,
    Mono,
    PageTitle,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatDuration, interpolateLogMessage } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { show as issueShow } from '@/routes/issues';
import { show as projectShow } from '@/routes/projects';
import { index as routesIndex } from '@/routes/routes';
import type { ProjectHeader } from '@/types/lumberjack';

type Entry = {
    type: 'query' | 'span' | 'log' | 'exception';
    offset_us: number | null;
    duration_us?: number | null;
    // query
    sql?: string;
    location?: string | null;
    // span
    kind?: string;
    name?: string;
    target?: string;
    attributes?: Record<string, unknown> | null;
    // log
    level?: string;
    message?: string | null;
    channel?: string | null;
    context?: Record<string, unknown> | null;
    // exception
    issue?: string | null;
    reference?: string | null;
    title?: string | null;
};

type Props = {
    project: ProjectHeader;
    trace: {
        id: string;
        requests: {
            method: string;
            route: string | null;
            route_name: string | null;
            domain: string | null;
            url: string;
            status: number;
            duration_us: number;
            host: string | null;
            offset_us: number | null;
        }[];
        jobs: {
            name: string;
            status: string;
            attempt: number;
            duration_us: number | null;
            host: string | null;
            offset_us: number | null;
        }[];
        runs: {
            kind: 'command' | 'schedule';
            name: string | null;
            status: string;
            duration_us: number | null;
            host: string | null;
            offset_us: number | null;
        }[];
        timeline: Entry[];
    };
};

const kindOf = (entry: Entry): string =>
    entry.type === 'span' ? (entry.kind ?? 'span') : entry.type;

const toneOf = (entry: Entry): Tone => {
    if (entry.type === 'exception') return 'err';
    if (entry.type === 'log')
        return ['error', 'critical', 'alert', 'emergency'].includes(
            entry.level ?? '',
        )
            ? 'err'
            : entry.level === 'warning'
              ? 'warn'
              : 'neutral';
    if (entry.type === 'query') return 'amber';
    const status = Number(entry.attributes?.status ?? 0);
    if (entry.kind === 'http')
        return entry.attributes?.failed || status >= 500
            ? 'err'
            : status >= 400
              ? 'warn'
              : 'info';
    if (entry.kind === 'cache') return entry.name === 'miss' ? 'warn' : 'ok';
    return 'neutral';
};

// Bar colors by tone, from the theme.
const barColor: Record<Tone, string> = {
    err: 'var(--lj-err)',
    warn: 'var(--lj-warn)',
    ok: 'var(--lj-ok)',
    info: 'var(--lj-info)',
    amber: 'var(--lj-amber)',
    neutral: 'var(--lj-ink-3)',
};

export default function TraceShow({ project, trace }: Props) {
    const t = useT();
    const request = trace.requests[0];
    const job = trace.jobs[0];
    const run = trace.runs[0];
    const entries = trace.timeline;

    // The axis spans the root (request or job) and everything on the trace.
    const total = Math.max(
        1,
        request ? (request.offset_us ?? 0) + request.duration_us : 0,
        job ? (job.offset_us ?? 0) + (job.duration_us ?? 0) : 0,
        run ? (run.offset_us ?? 0) + (run.duration_us ?? 0) : 0,
        ...entries.map((e) => (e.offset_us ?? 0) + (e.duration_us ?? 0)),
    );

    const count = (predicate: (e: Entry) => boolean) =>
        entries.filter(predicate).length;
    const misses = count((e) => e.kind === 'cache' && e.name === 'miss');
    const queryTime = entries
        .filter((e) => e.type === 'query')
        .reduce((sum, e) => sum + (e.duration_us ?? 0), 0);

    const summary = (
        [
            ['queries', count((e) => e.type === 'query')],
            ['http', count((e) => e.kind === 'http')],
            ['cache', count((e) => e.kind === 'cache')],
            ['mail', count((e) => e.kind === 'mail')],
            ['notification', count((e) => e.kind === 'notification')],
            ['redis', count((e) => e.kind === 'redis')],
            ['log', count((e) => e.type === 'log')],
            ['exception', count((e) => e.type === 'exception')],
        ] as const
    ).filter(([, n]) => n > 0);

    return (
        <>
            <Head title={`${t('traces.title')} ${trace.id}`} />
            <TopBar
                crumbs={[
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    {
                        label: t('traces.title'),
                        href: routesIndex(project.uuid).url,
                    },
                    // The full id is in the tab title.
                    {
                        label: trace.id.slice(0, 8),
                        mono: true,
                        title: trace.id,
                    },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={
                        request ? (
                            <span className="flex flex-wrap items-baseline gap-2">
                                <Mono>{request.method}</Mono>
                                <span>{request.route ?? request.url}</span>
                                {request.route_name && (
                                    <span className="font-mono text-sm text-ink-3">
                                        {request.route_name}
                                    </span>
                                )}
                                {request.domain && (
                                    <span className="font-mono text-sm text-ink-3">
                                        {request.domain}
                                    </span>
                                )}
                            </span>
                        ) : job ? (
                            <Mono>{job.name}</Mono>
                        ) : run?.name ? (
                            <Mono>{run.name}</Mono>
                        ) : (
                            t('traces.title')
                        )
                    }
                    description={
                        request
                            ? `${request.status} · ${formatDuration(request.duration_us)}${request.host ? ` · ${request.host}` : ''}`
                            : job
                              ? `${job.status} · ${formatDuration(job.duration_us)}${job.host ? ` · ${job.host}` : ''}`
                              : run
                                ? `${t(`traces.roots.${run.kind}`)} · ${run.status} · ${formatDuration(run.duration_us)}${run.host ? ` · ${run.host}` : ''}`
                                : t('traces.description')
                    }
                />

                {summary.length > 0 && (
                    <ul className="flex flex-wrap gap-2 text-[13px]">
                        {summary.map(([key, n]) => (
                            <li
                                key={key}
                                className="rounded-full border border-line bg-panel px-3 py-1 text-ink-2"
                            >
                                {t(`traces.summary.${key}`, {}, n)}
                                {key === 'queries' &&
                                    ` · ${formatDuration(queryTime)}`}
                                {key === 'cache' &&
                                    misses > 0 &&
                                    ` · ${t('traces.summary.cache_misses', { count: misses })}`}
                            </li>
                        ))}
                    </ul>
                )}

                <Card aria-labelledby="h-timeline">
                    <CardHeader
                        id="h-timeline"
                        title={t('traces.timeline')}
                        description={t('traces.timeline_hint')}
                    />
                    {entries.length === 0 ? (
                        <EmptyState title={t('traces.empty')} />
                    ) : (
                        <ol className="flex flex-col">
                            {entries.map((entry, i) => {
                                const tone = toneOf(entry);
                                const left =
                                    ((entry.offset_us ?? 0) / total) * 100;
                                const width = Math.max(
                                    0.4,
                                    ((entry.duration_us ?? 0) / total) * 100,
                                );

                                return (
                                    <li
                                        key={i}
                                        className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line/60 px-4 py-2 md:flex-nowrap"
                                    >
                                        <span className="w-[72px] shrink-0 font-mono text-xs text-ink-3 tabular-nums">
                                            +
                                            {formatDuration(
                                                entry.offset_us ?? 0,
                                            )}
                                        </span>
                                        <span className="w-[96px] shrink-0">
                                            <Badge tone={tone}>
                                                {t(
                                                    `traces.kinds.${kindOf(entry)}`,
                                                )}
                                            </Badge>
                                        </span>
                                        <EntryText entry={entry} />
                                        <span
                                            className="relative hidden h-2.5 w-[28%] min-w-[120px] shrink-0 rounded-sm bg-panel-2 md:block"
                                            aria-hidden="true"
                                        >
                                            <span
                                                className="absolute inset-y-0 rounded-sm"
                                                style={{
                                                    left: `${Math.min(left, 99.6)}%`,
                                                    width: `${Math.min(width, 100 - Math.min(left, 99.6))}%`,
                                                    // A 1 ms span in a long trace still shows.
                                                    minWidth: 2,
                                                    background: barColor[tone],
                                                }}
                                            />
                                        </span>
                                        <span className="hidden w-[64px] shrink-0 text-right font-mono text-xs text-ink-2 tabular-nums md:block">
                                            {entry.duration_us != null
                                                ? formatDuration(
                                                      entry.duration_us,
                                                  )
                                                : ''}
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </Card>
            </PageBody>
        </>
    );

    function EntryText({ entry }: { entry: Entry }) {
        if (entry.type === 'query') {
            return (
                <span className="flex min-w-0 flex-1 flex-col">
                    <HighlightedCode
                        code={entry.sql ?? ''}
                        language="sql"
                        className="truncate font-mono text-xs text-ink"
                    />
                    {entry.location && (
                        <span className="truncate font-mono text-[11px] text-ink-3">
                            {entry.location}
                        </span>
                    )}
                </span>
            );
        }

        if (entry.type === 'span') {
            const raw = entry.attributes?.status;
            const status =
                typeof raw === 'number' || typeof raw === 'string'
                    ? String(raw)
                    : undefined;
            const recipients = Number(entry.attributes?.recipients ?? 0);

            return (
                <span className="flex min-w-0 flex-1 items-baseline gap-2">
                    <span className="font-mono text-xs text-ink-2 uppercase">
                        {entry.name}
                    </span>
                    <span className="truncate font-mono text-xs text-ink">
                        {entry.target}
                    </span>
                    {entry.kind === 'http' && status !== undefined && (
                        <span className="font-mono text-xs text-ink-2">
                            {status}
                        </span>
                    )}
                    {entry.kind === 'mail' && recipients > 0 && (
                        <span className="text-xs text-ink-3">
                            {t('traces.recipients', {}, recipients)}
                        </span>
                    )}
                </span>
            );
        }

        if (entry.type === 'log') {
            return (
                <span className="flex min-w-0 flex-1 items-baseline gap-2">
                    <span className="font-mono text-xs text-ink-3">
                        {entry.channel}
                    </span>
                    <span className="truncate text-ink">
                        {interpolateLogMessage(
                            entry.message ?? '',
                            entry.context,
                        )}
                    </span>
                </span>
            );
        }

        return entry.issue ? (
            <Link
                href={issueShow([project.uuid, entry.issue]).url}
                className="min-w-0 flex-1 truncate text-err hover:underline"
            >
                {entry.reference ? `${entry.reference} ` : ''}
                {entry.title}
            </Link>
        ) : (
            <span className="min-w-0 flex-1 truncate text-err">
                {entry.title}
            </span>
        );
    }
}
