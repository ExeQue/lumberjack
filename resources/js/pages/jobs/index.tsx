import { Relative, useNow } from '@/components/lj/relative';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { TriangleAlert } from 'lucide-react';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    PageTitle,
    Select,
    Sparkline,
    numClass,
    spreadTips,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatDateTime,
    formatDuration,
    formatNumber,
    formatPercent,
    formatRelative,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useTeamLive } from '@/lib/use-live';
import { useProjectLive } from '@/lib/use-project-live';
import { show as issueShow } from '@/routes/issues';
import { show as traceShow } from '@/routes/traces';
import { index as jobsIndex } from '@/routes/jobs';
import { alert as queueAlert } from '@/routes/queues';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { show as projectShow } from '@/routes/projects';
import type {
    DashboardFilters,
    JobRunRow,
    JobStat,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Filters = DashboardFilters & { sort: string; queue: string; job: string };

type QueueStat = {
    connection: string;
    queue: string;
    size: number | null;
    oldest_age_s: number | null;
    /** Workers running on it; null where the agent does not know. */
    workers: number | null;
    sampled_at: string | null;
    p95_wait: number | null;
    series: { t: string; v: number }[];
};

type QueueThreshold = {
    connection: string;
    queue: string;
    size: number | null;
    oldest_minutes: number | null;
    sustained_minutes: number | null;
    muted: boolean;
};

type QueueAlerts = {
    rule: {
        size: number | null;
        age_minutes: number | null;
        sustain_minutes: number;
    } | null;
    own: QueueThreshold[];
    can_update: boolean;
};

type Props = {
    queue_alerts: QueueAlerts;
    project: ProjectHeader;
    filters: Filters;
    queues: string[];
    queue_stats: QueueStat[];
    stuck: number;
    stuck_names: string[];
    jobs: JobStat[];
    runs: JobRunRow[];
};

const statusTone: Record<JobRunRow['status'], Tone> = {
    queued: 'neutral',
    processing: 'info',
    processed: 'ok',
    released: 'neutral',
    failed: 'err',
    stuck: 'warn',
};

const outcome = [
    { key: 'processed', className: 'bg-ok' },
    { key: 'failed', className: 'bg-err' },
    { key: 'stuck', className: 'bg-warn' },
] as const;

function ClassName({ name }: { name: string }) {
    const cut = name.lastIndexOf('\\') + 1;

    return (
        <>
            <span className="text-ink-3">{name.slice(0, cut)}</span>
            {name.slice(cut)}
        </>
    );
}

/**
 * "order: App\\Models\\Order#42, notify: true", short enough for one line.
 */
function formatParameters(parameters: Record<string, unknown>): string {
    return Object.entries(parameters)
        .map(
            ([key, value]) =>
                `${key}: ${typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value as string | number | boolean | null)}`,
        )
        .join(', ');
}

export default function JobsIndex({
    project,
    filters,
    queues,
    queue_stats,
    queue_alerts,
    stuck,
    stuck_names,
    jobs,
    runs,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;

    useProjectLive(
        project.uuid,
        ['jobs.changed'],
        ['jobs', 'runs', 'stuck', 'stuck_names'],
    );
    // Queue sizes come with each agent's heartbeat.
    useProjectLive(project.uuid, ['agents.changed'], ['queue_stats']);
    // Queue alert thresholds: the project's own, and the team's rule.
    useProjectLive(project.uuid, ['settings.changed'], ['queue_alerts']);
    useTeamLive(['alerts.changed'], ['queue_alerts']);

    const apply = (changes: Partial<Filters>) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        ) as Record<string, string>;

        router.get(
            jobsIndex(project.uuid, { query }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const totalRuns = jobs.reduce((sum, j) => sum + j.runs, 0);

    return (
        <>
            <Head title={`${t('jobs.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('jobs.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody wide>
                <PageTitle
                    title={t('jobs.title')}
                    description={t(
                        'jobs.description',
                        { runs: formatNumber(totalRuns) },
                        jobs.length,
                    )}
                    actions={
                        <>
                            <label className="sr-only" htmlFor="job-queue">
                                {t('jobs.queue')}
                            </label>
                            <Select
                                id="job-queue"
                                value={filters.queue}
                                onChange={(e) =>
                                    apply({ queue: e.target.value })
                                }
                            >
                                <option value="">{t('jobs.all_queues')}</option>
                                {queues.map((q) => (
                                    <option key={q} value={q}>
                                        {q}
                                    </option>
                                ))}
                            </Select>
                            <label className="sr-only" htmlFor="job-sort">
                                {t('jobs.sort.label')}
                            </label>
                            <Select
                                id="job-sort"
                                value={filters.sort}
                                onChange={(e) =>
                                    apply({ sort: e.target.value })
                                }
                            >
                                <option value="errors">
                                    {t('jobs.sort.errors')}
                                </option>
                                <option value="count">
                                    {t('jobs.sort.count')}
                                </option>
                                <option value="duration">
                                    {t('jobs.sort.duration')}
                                </option>
                                <option value="wait">
                                    {t('jobs.sort.wait')}
                                </option>
                            </Select>
                        </>
                    }
                />

                {stuck > 0 && (
                    <div
                        role="status"
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warn-line bg-warn-bg px-4 py-3"
                    >
                        <span className="flex items-center gap-2.5">
                            <TriangleAlert
                                className="size-4 shrink-0 text-warn"
                                aria-hidden="true"
                            />
                            <span>
                                <strong className="font-semibold">
                                    {t('jobs.stuck.title', {}, stuck)}
                                </strong>{' '}
                                {stuck_names.length > 0 && (
                                    <span className="font-mono text-xs">
                                        {stuck_names.join(', ')}
                                    </span>
                                )}{' '}
                                {t('jobs.stuck.body')}
                            </span>
                        </span>
                        <a
                            href="#runs"
                            className="text-xs text-amber hover:underline"
                        >
                            {t('jobs.stuck.link')}
                        </a>
                    </div>
                )}

                {queue_stats.length > 0 && (
                    <QueuesCard
                        stats={queue_stats}
                        period={filters.period}
                        alerts={queue_alerts}
                        project={project.uuid}
                    />
                )}

                <Card className="overflow-x-auto">
                    {jobs.length === 0 ? (
                        <EmptyState title={t('jobs.empty_title')}>
                            {t('jobs.empty_body')}
                        </EmptyState>
                    ) : (
                        <>
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('jobs.columns.job')}
                                        </th>
                                        <th className={thClass}>
                                            {t('jobs.columns.queue')}
                                        </th>
                                        <th className={`${thClass} text-right`}>
                                            {t('jobs.columns.runs')}
                                        </th>
                                        <th className={`${thClass} text-right`}>
                                            {t('jobs.columns.failure_rate')}
                                        </th>
                                        <th className={`${thClass} text-right`}>
                                            {t('jobs.columns.p95_duration')}
                                        </th>
                                        <th className={`${thClass} text-right`}>
                                            {t('jobs.columns.p95_wait')}
                                        </th>
                                        <th className={`${thClass} text-right`}>
                                            {t('jobs.columns.retries')}
                                        </th>
                                        <th className={thClass}>
                                            {t('jobs.columns.outcome')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {jobs.map((j) => {
                                        const active = filters.job === j.name;
                                        const total = Math.max(
                                            1,
                                            j.processed + j.failed + j.stuck,
                                        );

                                        return (
                                            <tr
                                                key={j.name}
                                                className={cn(
                                                    'lj-row hover:bg-panel-2/60',
                                                    active && 'bg-panel-2',
                                                )}
                                            >
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        active &&
                                                            'shadow-[inset_2px_0_0_var(--lj-amber)]',
                                                    )}
                                                >
                                                    <button
                                                        type="button"
                                                        aria-pressed={active}
                                                        onClick={() =>
                                                            apply({
                                                                job: active
                                                                    ? ''
                                                                    : j.name,
                                                            })
                                                        }
                                                        className="lj-row-link cursor-pointer text-left font-mono text-xs text-ink hover:text-amber"
                                                    >
                                                        <ClassName
                                                            name={j.name}
                                                        />
                                                    </button>
                                                </td>
                                                <td className={tdClass}>
                                                    <Badge>{j.queue}</Badge>
                                                </td>
                                                <td
                                                    className={`${tdClass} ${numClass}`}
                                                >
                                                    {formatNumber(j.runs)}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        numClass,
                                                        j.failure_rate > 0.01
                                                            ? 'text-err'
                                                            : j.failure_rate ===
                                                                  0 &&
                                                                  'text-ink-3',
                                                    )}
                                                >
                                                    {formatPercent(
                                                        j.failure_rate,
                                                    )}
                                                </td>
                                                <td
                                                    className={`${tdClass} ${numClass}`}
                                                >
                                                    {formatDuration(
                                                        j.p95_duration,
                                                    )}
                                                </td>
                                                <td
                                                    className={`${tdClass} ${numClass}`}
                                                >
                                                    {formatDuration(j.p95_wait)}
                                                </td>
                                                <td
                                                    className={`${tdClass} ${numClass}`}
                                                >
                                                    {formatNumber(j.retries)}
                                                </td>
                                                <td
                                                    className={`${tdClass} min-w-40`}
                                                >
                                                    <div
                                                        role="img"
                                                        aria-label={t(
                                                            'jobs.outcome_label',
                                                            {
                                                                processed:
                                                                    j.processed,
                                                                failed: j.failed,
                                                                stuck: j.stuck,
                                                            },
                                                        )}
                                                        className="flex h-2 overflow-hidden rounded-full bg-line"
                                                    >
                                                        {outcome.map((o) => (
                                                            <div
                                                                key={o.key}
                                                                className={
                                                                    o.className
                                                                }
                                                                style={{
                                                                    width: `${(j[o.key] / total) * 100}%`,
                                                                }}
                                                            />
                                                        ))}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                            <div
                                aria-hidden="true"
                                className="flex flex-wrap gap-3 px-4 py-3 text-xs text-ink-2"
                            >
                                {outcome.map((o) => (
                                    <span
                                        key={o.key}
                                        className="inline-flex items-center gap-1.5"
                                    >
                                        <span
                                            className={`size-2.5 rounded-sm ${o.className}`}
                                        />
                                        {t(`common.job_statuses.${o.key}`)}
                                    </span>
                                ))}
                            </div>
                        </>
                    )}
                </Card>

                <Card id="runs" aria-labelledby="runs-title">
                    <CardHeader
                        id="runs-title"
                        title={
                            filters.job
                                ? t('jobs.runs.title_job', { job: filters.job })
                                : t('jobs.runs.title')
                        }
                        aside={
                            filters.job && (
                                <Button onClick={() => apply({ job: '' })}>
                                    {t('jobs.runs.clear')}
                                </Button>
                            )
                        }
                    />
                    {runs.length === 0 ? (
                        <EmptyState title={t('jobs.runs.empty')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('jobs.runs.columns.status')}
                                        </th>
                                        <th className={thClass}>
                                            {t('jobs.runs.columns.job')}
                                        </th>
                                        <th className={thClass}>
                                            {t('jobs.runs.columns.attempt')}
                                        </th>
                                        <th className={thClass}>
                                            {t('jobs.runs.columns.queued')}
                                        </th>
                                        <th className={`${thClass} text-right`}>
                                            {t('jobs.runs.columns.wait')}
                                        </th>
                                        <th className={`${thClass} text-right`}>
                                            {t('jobs.runs.columns.duration')}
                                        </th>
                                        <th className={thClass}>
                                            {t('jobs.runs.columns.host')}
                                        </th>
                                        <th className={thClass}>
                                            <span className="sr-only">
                                                {t('jobs.runs.columns.issue')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {runs.map((r) => (
                                        <tr
                                            key={r.id}
                                            className="hover:bg-panel-2/60"
                                        >
                                            <td className={tdClass}>
                                                <Badge
                                                    tone={statusTone[r.status]}
                                                >
                                                    {t(
                                                        `common.job_statuses.${r.status}`,
                                                    )}
                                                </Badge>
                                            </td>
                                            <td
                                                className={`${tdClass} font-mono text-xs`}
                                            >
                                                <ClassName name={r.name} />
                                                {r.parameters && (
                                                    <details className="group mt-0.5 max-w-[60ch] text-[11px] text-ink-3">
                                                        <summary className="cursor-pointer list-none truncate group-open:hidden hover:text-ink-2">
                                                            {formatParameters(
                                                                r.parameters,
                                                            )}
                                                        </summary>
                                                        <summary className="hidden cursor-pointer list-none group-open:block hover:text-ink-2">
                                                            {t(
                                                                'jobs.runs.parameters',
                                                            )}
                                                        </summary>
                                                        <dl className="mt-1 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-3 gap-y-0.5">
                                                            {Object.entries(
                                                                r.parameters,
                                                            ).map(
                                                                ([
                                                                    key,
                                                                    value,
                                                                ]) => (
                                                                    <div
                                                                        key={
                                                                            key
                                                                        }
                                                                        className="contents"
                                                                    >
                                                                        <dt>
                                                                            {
                                                                                key
                                                                            }
                                                                        </dt>
                                                                        <dd className="break-all whitespace-pre-wrap text-ink-2">
                                                                            {typeof value ===
                                                                                'object' &&
                                                                            value !==
                                                                                null
                                                                                ? JSON.stringify(
                                                                                      value,
                                                                                      null,
                                                                                      2,
                                                                                  )
                                                                                : String(
                                                                                      value as
                                                                                          | string
                                                                                          | number
                                                                                          | boolean
                                                                                          | null,
                                                                                  )}
                                                                        </dd>
                                                                    </div>
                                                                ),
                                                            )}
                                                        </dl>
                                                    </details>
                                                )}
                                            </td>
                                            <td
                                                className={`${tdClass} font-mono text-xs whitespace-nowrap`}
                                            >
                                                {r.max_tries
                                                    ? `${r.attempt}/${r.max_tries}`
                                                    : r.attempt}
                                            </td>
                                            <td
                                                className={`${tdClass} font-mono text-xs whitespace-nowrap text-ink-2`}
                                            >
                                                {r.queued_at ? (
                                                    <time
                                                        dateTime={r.queued_at}
                                                        title={formatDateTime(
                                                            r.queued_at,
                                                        )}
                                                    >
                                                        <Relative
                                                            iso={r.queued_at}
                                                        />
                                                    </time>
                                                ) : (
                                                    '-'
                                                )}
                                            </td>
                                            <td
                                                className={`${tdClass} ${numClass}`}
                                            >
                                                {formatDuration(r.wait_us)}
                                            </td>
                                            <td
                                                className={`${tdClass} ${numClass}`}
                                            >
                                                {formatDuration(r.duration_us)}
                                            </td>
                                            <td
                                                className={`${tdClass} font-mono text-xs`}
                                            >
                                                {r.host ?? '-'}
                                            </td>
                                            <td
                                                className={`${tdClass} text-right`}
                                            >
                                                <span className="inline-flex gap-3">
                                                    {r.trace_id && (
                                                        <Link
                                                            href={
                                                                traceShow([
                                                                    project.uuid,
                                                                    r.trace_id,
                                                                ]).url
                                                            }
                                                            className="text-xs text-ink-2 hover:text-amber hover:underline"
                                                        >
                                                            {t('traces.open')}
                                                        </Link>
                                                    )}
                                                    {r.issue && (
                                                        <Link
                                                            href={
                                                                issueShow([
                                                                    project.uuid,
                                                                    r.issue,
                                                                ]).url
                                                            }
                                                            className="text-xs text-amber hover:underline"
                                                        >
                                                            {t(
                                                                'jobs.runs.view_issue',
                                                            )}
                                                        </Link>
                                                    )}
                                                </span>
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

/** A sample older than this means its agent stopped reporting. */
const STALE_MS = 5 * 60_000;

/**
 * One queue's own backlog thresholds: an empty field uses the rule's, muted never alerts.
 */
function QueueAlertDialog({
    project,
    queue,
    own,
    rule,
    onClose,
}: {
    project: string;
    queue: { connection: string; queue: string };
    own: QueueThreshold | undefined;
    rule: QueueAlerts['rule'];
    onClose: () => void;
}) {
    const t = useT();
    const form = useForm({
        connection: queue.connection,
        queue: queue.queue,
        size: own?.size?.toString() ?? '',
        oldest_minutes: own?.oldest_minutes?.toString() ?? '',
        sustained_minutes: own?.sustained_minutes?.toString() ?? '',
        muted: own?.muted ?? false,
    });
    const placeholder = (value: number | null | undefined) =>
        value === null || value === undefined
            ? t('jobs.queues.alert.off')
            : t('jobs.queues.alert.rule_value', { value: String(value) });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            size: data.size === '' ? null : Number(data.size),
                            oldest_minutes:
                                data.oldest_minutes === ''
                                    ? null
                                    : Number(data.oldest_minutes),
                            sustained_minutes:
                                data.sustained_minutes === ''
                                    ? null
                                    : Number(data.sustained_minutes),
                        }));
                        form.put(queueAlert(project).url, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t('jobs.queues.alert.title', {
                                queue: `${queue.connection}:${queue.queue}`,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('jobs.queues.alert.description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label={t('jobs.queues.alert.size')}
                        htmlFor="queue-alert-size"
                        error={form.errors.size}
                    >
                        <Input
                            id="queue-alert-size"
                            type="number"
                            min={1}
                            value={form.data.size}
                            placeholder={placeholder(rule?.size)}
                            onChange={(e) =>
                                form.setData('size', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label={t('jobs.queues.alert.oldest')}
                        htmlFor="queue-alert-oldest"
                        error={form.errors.oldest_minutes}
                    >
                        <Input
                            id="queue-alert-oldest"
                            type="number"
                            min={1}
                            value={form.data.oldest_minutes}
                            placeholder={placeholder(rule?.age_minutes)}
                            onChange={(e) =>
                                form.setData('oldest_minutes', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label={t('jobs.queues.alert.sustained')}
                        htmlFor="queue-alert-sustained"
                        error={form.errors.sustained_minutes}
                    >
                        <Input
                            id="queue-alert-sustained"
                            type="number"
                            min={1}
                            value={form.data.sustained_minutes}
                            placeholder={placeholder(rule?.sustain_minutes)}
                            onChange={(e) =>
                                form.setData(
                                    'sustained_minutes',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.muted}
                            onChange={(e) =>
                                form.setData('muted', e.target.checked)
                            }
                        />
                        {t('jobs.queues.alert.muted')}
                    </label>
                    <DialogFooter>
                        <Button type="button" onClick={onClose}>
                            {t('jobs.queues.alert.cancel')}
                        </Button>
                        <Button
                            variant="default"
                            type="submit"
                            disabled={form.processing}
                        >
                            {t('jobs.queues.alert.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function QueuesCard({
    stats,
    period,
    alerts,
    project,
}: {
    stats: QueueStat[];
    period: string;
    alerts: QueueAlerts;
    project: string;
}) {
    // Relative times in the text below stay current.
    const now = useNow();
    const t = useT();
    const [editing, setEditing] = useState<QueueStat | null>(null);
    const ownOf = (q: { connection: string; queue: string }) =>
        alerts.own.find(
            (o) => o.connection === q.connection && o.queue === q.queue,
        );

    return (
        <Card aria-labelledby="queues-title">
            <CardHeader
                id="queues-title"
                title={t('jobs.queues.title')}
                description={t('jobs.queues.description')}
            />
            <div className="overflow-x-auto">
                <table className={tableClass}>
                    <thead>
                        <tr>
                            <th className={thClass}>
                                {t('jobs.queues.columns.queue')}
                            </th>
                            <th className={`${thClass} text-right`}>
                                {t('jobs.queues.columns.size')}
                            </th>
                            <th className={`${thClass} text-right`}>
                                {t('jobs.queues.columns.workers')}
                            </th>
                            <th className={`${thClass} text-right`}>
                                {t('jobs.queues.columns.oldest')}
                            </th>
                            <th className={`${thClass} text-right`}>
                                {t('jobs.queues.columns.p95_wait')}
                            </th>
                            <th className={thClass}>
                                {t('jobs.queues.columns.trend')}
                            </th>
                            <th className={thClass}>
                                <span className="sr-only">
                                    {t('jobs.queues.columns.alert')}
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {stats.map((q) => {
                            const values = q.series.map((p) => p.v);
                            const stale =
                                q.sampled_at !== null &&
                                Date.now() - new Date(q.sampled_at).getTime() >
                                    STALE_MS;

                            return (
                                <tr
                                    key={`${q.connection}:${q.queue}`}
                                    className="hover:bg-panel-2/60"
                                >
                                    <td className={tdClass}>
                                        <span className="flex items-center gap-2">
                                            <Badge>{q.connection}</Badge>
                                            <span className="font-mono text-xs">
                                                {q.queue}
                                            </span>
                                        </span>
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            numClass,
                                            stale && 'text-ink-3',
                                        )}
                                        title={
                                            q.sampled_at
                                                ? t('jobs.queues.sampled', {
                                                      time: formatRelative(
                                                          q.sampled_at,
                                                          now,
                                                      ),
                                                  })
                                                : undefined
                                        }
                                    >
                                        {q.size === null
                                            ? '-'
                                            : formatNumber(q.size)}
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            numClass,
                                            // Jobs wait and nobody takes them.
                                            q.workers === 0 &&
                                                (q.size ?? 0) > 0 &&
                                                'text-err',
                                        )}
                                    >
                                        {q.workers === null
                                            ? '-'
                                            : formatNumber(q.workers)}
                                    </td>
                                    <td className={`${tdClass} ${numClass}`}>
                                        {formatDuration(
                                            q.oldest_age_s === null
                                                ? null
                                                : q.oldest_age_s * 1_000_000,
                                        )}
                                    </td>
                                    <td className={`${tdClass} ${numClass}`}>
                                        {formatDuration(q.p95_wait)}
                                    </td>
                                    <td className={`${tdClass} min-w-48`}>
                                        {values.length > 0 && (
                                            <div className="flex items-center gap-2">
                                                <div
                                                    className="flex h-7 w-10 shrink-0 flex-col justify-between text-right font-mono text-[10px] leading-none text-ink-3"
                                                    aria-hidden="true"
                                                >
                                                    <span>
                                                        {formatNumber(
                                                            Math.max(
                                                                1,
                                                                ...values,
                                                            ),
                                                        )}
                                                    </span>
                                                    <span>0</span>
                                                </div>
                                                <Sparkline
                                                    values={values}
                                                    width={160}
                                                    color="var(--lj-info)"
                                                    tips={spreadTips(
                                                        values,
                                                        period,
                                                        formatNumber,
                                                    )}
                                                    label={t(
                                                        'jobs.queues.trend_label',
                                                        {
                                                            queue: q.queue,
                                                        },
                                                    )}
                                                />
                                            </div>
                                        )}
                                    </td>
                                    <td
                                        className={`${tdClass} whitespace-nowrap`}
                                    >
                                        <span className="flex items-center justify-end gap-2">
                                            {ownOf(q) && (
                                                <Badge
                                                    tone={
                                                        ownOf(q)?.muted
                                                            ? 'neutral'
                                                            : 'info'
                                                    }
                                                >
                                                    {ownOf(q)?.muted
                                                        ? t(
                                                              'jobs.queues.alert.muted_badge',
                                                          )
                                                        : t(
                                                              'jobs.queues.alert.custom',
                                                          )}
                                                </Badge>
                                            )}
                                            {alerts.can_update && (
                                                <Button
                                                    onClick={() =>
                                                        setEditing(q)
                                                    }
                                                    aria-label={t(
                                                        'jobs.queues.alert.edit',
                                                        { queue: q.queue },
                                                    )}
                                                >
                                                    {t(
                                                        'jobs.queues.alert.button',
                                                    )}
                                                </Button>
                                            )}
                                        </span>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            {editing && (
                <QueueAlertDialog
                    project={project}
                    queue={editing}
                    own={ownOf(editing)}
                    rule={alerts.rule}
                    onClose={() => setEditing(null)}
                />
            )}
        </Card>
    );
}
