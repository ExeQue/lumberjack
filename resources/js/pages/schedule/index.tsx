import { Head, Link, router, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    PageTitle,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatDateTime, formatDuration, formatRelative } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import { show as issueShow } from '@/routes/issues';
import { show as traceShow } from '@/routes/traces';
import { show as projectShow } from '@/routes/projects';
import {
    destroy as forgetTask,
    index as scheduleIndex,
} from '@/routes/schedule';
import type {
    DashboardFilters,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Status = 'running' | 'finished' | 'failed' | 'skipped' | 'missed';

type Task = {
    id: number;
    name: string;
    environment: string | null;
    expression: string;
    timezone: string | null;
    last_status: Status | null;
    last_run_at: string | null;
    last_duration_us: number | null;
    next_run_at: string | null;
    late: boolean;
    recent: Status[];
};

type Run = {
    id: number;
    status: Status;
    started_at: string | null;
    duration_us: number | null;
    exit_code: number | null;
    host: string | null;
    trace_id: string | null;
    issue: string | null;
};

type Filters = DashboardFilters & { task: number | null };

type Props = {
    project: ProjectHeader;
    filters: Filters;
    tasks: Task[];
    runs: Run[];
};

const tone: Record<Status, Tone> = {
    running: 'info',
    finished: 'ok',
    failed: 'err',
    skipped: 'neutral',
    missed: 'warn',
};

const dot: Record<Status, string> = {
    running: 'bg-info',
    finished: 'bg-ok',
    failed: 'bg-err',
    skipped: 'bg-line',
    missed: 'bg-warn',
};

const GRACE_MINUTES = 5;

function When({ iso }: { iso: string | null }) {
    const t = useT();

    return iso ? (
        <time dateTime={iso} title={formatDateTime(iso)}>
            {formatRelative(iso)}
        </time>
    ) : (
        <span className="text-ink-3">{t('schedule.never')}</span>
    );
}

export default function ScheduleIndex({
    project,
    filters,
    tasks,
    runs,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const selected = tasks.find((task) => task.id === filters.task) ?? null;
    const attention = tasks.filter(
        (task) =>
            task.last_status === 'failed' || task.last_status === 'missed',
    ).length;

    useProjectLive(project.id, ['schedule.changed'], ['tasks', 'runs']);

    const select = (task: number | null) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, task }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        ) as Record<string, string>;

        router.get(
            scheduleIndex(project.uuid, { query }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`${t('schedule.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('schedule.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody wide>
                <PageTitle
                    title={t('schedule.title')}
                    description={t('schedule.description', {}, tasks.length)}
                />

                {attention > 0 && (
                    <div
                        role="status"
                        className="flex items-center gap-2.5 rounded-lg border border-warn-line bg-warn-bg px-4 py-3"
                    >
                        <TriangleAlert
                            className="size-4 shrink-0 text-warn"
                            aria-hidden="true"
                        />
                        <strong className="font-semibold">
                            {t('schedule.attention', {}, attention)}
                        </strong>
                    </div>
                )}

                <Card aria-labelledby="tasks-title">
                    <CardHeader
                        id="tasks-title"
                        title={t('schedule.title')}
                        description={t('schedule.hint', {
                            grace: GRACE_MINUTES,
                        })}
                    />
                    {tasks.length === 0 ? (
                        <EmptyState title={t('schedule.empty_title')}>
                            {t('schedule.empty_body')}
                        </EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('schedule.columns.task')}
                                        </th>
                                        <th className={thClass}>
                                            {t('schedule.columns.expression')}
                                        </th>
                                        <th className={thClass}>
                                            {t('schedule.columns.status')}
                                        </th>
                                        <th className={thClass}>
                                            {t('schedule.columns.last_run')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('schedule.columns.duration')}
                                        </th>
                                        <th className={thClass}>
                                            {t('schedule.columns.next_run')}
                                        </th>
                                        <th className={thClass}>
                                            {t('schedule.columns.recent')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {tasks.map((task) => (
                                        <tr
                                            key={task.id}
                                            aria-selected={
                                                task.id === filters.task
                                            }
                                            className={cn(
                                                'hover:bg-panel-2/60',
                                                task.id === filters.task &&
                                                    'bg-panel-2',
                                            )}
                                        >
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'font-mono text-xs',
                                                )}
                                            >
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        select(
                                                            task.id ===
                                                                filters.task
                                                                ? null
                                                                : task.id,
                                                        )
                                                    }
                                                    className="text-left hover:text-amber"
                                                >
                                                    {task.name}
                                                </button>
                                                {task.environment && (
                                                    <span className="ml-2 text-ink-3">
                                                        {task.environment}
                                                    </span>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'font-mono text-xs whitespace-nowrap text-ink-2',
                                                )}
                                                title={
                                                    task.timezone ?? undefined
                                                }
                                            >
                                                {task.expression}
                                            </td>
                                            <td className={tdClass}>
                                                <span className="flex flex-wrap gap-1">
                                                    {task.last_status && (
                                                        <Badge
                                                            tone={
                                                                tone[
                                                                    task
                                                                        .last_status
                                                                ]
                                                            }
                                                        >
                                                            {t(
                                                                `schedule.statuses.${task.last_status}`,
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {task.late &&
                                                        task.last_status !==
                                                            'missed' && (
                                                            <Badge tone="warn">
                                                                {t(
                                                                    'schedule.late',
                                                                )}
                                                            </Badge>
                                                        )}
                                                </span>
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                <When iso={task.last_run_at} />
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {formatDuration(
                                                    task.last_duration_us,
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                <When iso={task.next_run_at} />
                                            </td>
                                            <td className={tdClass}>
                                                <span
                                                    className="flex items-center gap-0.5"
                                                    role="img"
                                                    aria-label={t(
                                                        'schedule.recent_label',
                                                        {
                                                            count: task.recent
                                                                .length,
                                                        },
                                                    )}
                                                >
                                                    {task.recent.map(
                                                        (status, i) => (
                                                            <span
                                                                key={i}
                                                                title={t(
                                                                    `schedule.statuses.${status}`,
                                                                )}
                                                                className={cn(
                                                                    'h-3.5 w-1.5 rounded-sm',
                                                                    dot[status],
                                                                )}
                                                            />
                                                        ),
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

                {selected && (
                    <Card aria-labelledby="runs-title">
                        <CardHeader
                            id="runs-title"
                            title={t('schedule.runs.title', {
                                task: selected.name,
                            })}
                            aside={
                                <span className="flex gap-2">
                                    {project.can_administer && (
                                        <ConfirmButton
                                            question={t(
                                                'schedule.forget_question',
                                                { task: selected.name },
                                            )}
                                            confirmLabel={t('schedule.forget')}
                                            onConfirm={() =>
                                                router.delete(
                                                    forgetTask([
                                                        project.uuid,
                                                        selected.id,
                                                    ]).url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('schedule.forget')}
                                        </ConfirmButton>
                                    )}
                                    <Button
                                        variant="ghost"
                                        onClick={() => select(null)}
                                    >
                                        {t('schedule.runs.close')}
                                    </Button>
                                </span>
                            }
                        />
                        {runs.length === 0 ? (
                            <EmptyState title={t('schedule.runs.empty')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className={tableClass}>
                                    <thead>
                                        <tr>
                                            <th className={thClass}>
                                                {t('schedule.columns.status')}
                                            </th>
                                            <th className={thClass}>
                                                {t('schedule.runs.started')}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'text-right',
                                                )}
                                            >
                                                {t('schedule.columns.duration')}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'text-right',
                                                )}
                                            >
                                                {t('schedule.runs.exit_code')}
                                            </th>
                                            <th className={thClass}>
                                                {t('schedule.runs.host')}
                                            </th>
                                            <th className={thClass}>
                                                <span className="sr-only">
                                                    {t(
                                                        'schedule.runs.view_issue',
                                                    )}
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {runs.map((run) => (
                                            <tr
                                                key={run.id}
                                                className="hover:bg-panel-2/60"
                                            >
                                                <td className={tdClass}>
                                                    <Badge
                                                        tone={tone[run.status]}
                                                    >
                                                        {t(
                                                            `schedule.statuses.${run.status}`,
                                                        )}
                                                    </Badge>
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-xs whitespace-nowrap text-ink-2',
                                                    )}
                                                >
                                                    <When
                                                        iso={run.started_at}
                                                    />
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        numClass,
                                                    )}
                                                >
                                                    {formatDuration(
                                                        run.duration_us,
                                                    )}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        numClass,
                                                    )}
                                                >
                                                    {run.exit_code ?? '-'}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'font-mono text-xs',
                                                    )}
                                                >
                                                    {run.host ?? '-'}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-right',
                                                    )}
                                                >
                                                    <span className="inline-flex gap-3">
                                                        {run.trace_id && (
                                                            <Link
                                                                href={
                                                                    traceShow([
                                                                        project.uuid,
                                                                        run.trace_id,
                                                                    ]).url
                                                                }
                                                                className="text-xs text-ink-2 hover:text-amber hover:underline"
                                                            >
                                                                {t(
                                                                    'traces.open',
                                                                )}
                                                            </Link>
                                                        )}
                                                        {run.issue && (
                                                            <Link
                                                                href={
                                                                    issueShow([
                                                                        project.uuid,
                                                                        run.issue,
                                                                    ]).url
                                                                }
                                                                className="text-xs text-amber hover:underline"
                                                            >
                                                                {t(
                                                                    'schedule.runs.view_issue',
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
                )}
            </PageBody>
        </>
    );
}
