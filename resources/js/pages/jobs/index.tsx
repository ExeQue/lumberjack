import { Head, Link, router, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    PageTitle,
    Select,
    numClass,
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
import { useProjectLive } from '@/lib/use-project-live';
import { show as issueShow } from '@/routes/issues';
import { show as traceShow } from '@/routes/traces';
import { index as jobsIndex } from '@/routes/jobs';
import { show as projectShow } from '@/routes/projects';
import type {
    DashboardFilters,
    JobRunRow,
    JobStat,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Filters = DashboardFilters & { sort: string; queue: string; job: string };

type Props = {
    project: ProjectHeader;
    filters: Filters;
    queues: string[];
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
                                                        {formatRelative(
                                                            r.queued_at,
                                                        )}
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
