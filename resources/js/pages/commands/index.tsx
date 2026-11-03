import { Relative } from '@/components/lj/relative';
import { Head, Link, router, usePage } from '@inertiajs/react';
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
import { formatDateTime, formatDuration, formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import { index as commandsIndex } from '@/routes/commands';
import { show as issueShow } from '@/routes/issues';
import { show as traceShow } from '@/routes/traces';
import { show as projectShow } from '@/routes/projects';
import type {
    DashboardFilters,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Status = 'running' | 'finished' | 'failed';

type CommandStat = {
    name: string;
    runs: number;
    failed: number;
    avg_us: number;
    max_us: number;
    last_run_at: string | null;
    last_status: Status | null;
};

type Run = {
    id: number;
    name: string;
    status: Status;
    arguments: Record<string, unknown> | null;
    options: Record<string, unknown> | null;
    exit_code: number | null;
    started_at: string | null;
    duration_us: number | null;
    host: string | null;
    trace_id: string | null;
    issue: string | null;
};

type Filters = DashboardFilters & { command: string };

type Props = {
    project: ProjectHeader;
    filters: Filters;
    commands: CommandStat[];
    runs: Run[];
};

const tone: Record<Status, Tone> = {
    running: 'info',
    finished: 'ok',
    failed: 'err',
};

/**
 * The input as it would be typed: "daily --force --limit=50".
 */
function commandLine(run: Run): string {
    const show = (value: unknown): string =>
        typeof value === 'object' && value !== null
            ? JSON.stringify(value)
            : String(value as string | number | boolean);
    const args = Object.values(run.arguments ?? {}).map(show);
    const options = Object.entries(run.options ?? {}).map(([key, value]) =>
        value === true ? `--${key}` : `--${key}=${show(value)}`,
    );

    return [...args, ...options].join(' ');
}

export default function CommandsIndex({
    project,
    filters,
    commands,
    runs,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;

    useProjectLive(project.uuid, ['commands.changed'], ['commands', 'runs']);

    const apply = (changes: Partial<Filters>) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        ) as Record<string, string>;

        router.get(
            commandsIndex(project.uuid, { query }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`${t('commands.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('commands.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody wide>
                <PageTitle
                    title={t('commands.title')}
                    description={t('commands.description', {}, commands.length)}
                />

                <Card aria-labelledby="commands-title">
                    <CardHeader
                        id="commands-title"
                        title={t('commands.title')}
                    />
                    {commands.length === 0 ? (
                        <EmptyState title={t('commands.empty_title')}>
                            {t('commands.empty_body')}
                        </EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('commands.columns.command')}
                                        </th>
                                        <th className={thClass}>
                                            {t('commands.columns.status')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('commands.columns.runs')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('commands.columns.failed')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('commands.columns.avg')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('commands.columns.max')}
                                        </th>
                                        <th className={thClass}>
                                            {t('commands.columns.last_run')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {commands.map((c) => (
                                        <tr
                                            key={c.name}
                                            className={cn(
                                                'lj-row hover:bg-panel-2/60',
                                                c.name === filters.command &&
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
                                                        apply({
                                                            command:
                                                                c.name ===
                                                                filters.command
                                                                    ? ''
                                                                    : c.name,
                                                        })
                                                    }
                                                    className="lj-row-link text-left hover:text-amber"
                                                >
                                                    {c.name}
                                                </button>
                                            </td>
                                            <td className={tdClass}>
                                                {c.last_status && (
                                                    <Badge
                                                        tone={
                                                            tone[c.last_status]
                                                        }
                                                    >
                                                        {t(
                                                            `commands.statuses.${c.last_status}`,
                                                        )}
                                                    </Badge>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {formatNumber(c.runs)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                    c.failed > 0 && 'text-err',
                                                )}
                                            >
                                                {formatNumber(c.failed)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {formatDuration(c.avg_us)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {formatDuration(c.max_us)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                {c.last_run_at && (
                                                    <time
                                                        dateTime={c.last_run_at}
                                                        title={formatDateTime(
                                                            c.last_run_at,
                                                        )}
                                                    >
                                                        <Relative
                                                            iso={c.last_run_at}
                                                        />
                                                    </time>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                <Card aria-labelledby="runs-title">
                    <CardHeader
                        id="runs-title"
                        title={
                            filters.command
                                ? t('commands.runs.title_command', {
                                      command: filters.command,
                                  })
                                : t('commands.runs.title')
                        }
                        aside={
                            filters.command && (
                                <Button
                                    variant="ghost"
                                    onClick={() => apply({ command: '' })}
                                >
                                    {t('commands.runs.clear')}
                                </Button>
                            )
                        }
                    />
                    {runs.length === 0 ? (
                        <EmptyState title={t('commands.runs.empty')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('commands.columns.status')}
                                        </th>
                                        <th className={thClass}>
                                            {t('commands.columns.command')}
                                        </th>
                                        <th className={thClass}>
                                            {t('commands.columns.input')}
                                        </th>
                                        <th className={thClass}>
                                            {t('commands.columns.started')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('commands.columns.duration')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('commands.columns.exit_code')}
                                        </th>
                                        <th className={thClass}>
                                            {t('commands.columns.host')}
                                        </th>
                                        <th className={thClass}>
                                            <span className="sr-only">
                                                {t('commands.runs.view_issue')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {runs.map((run) => {
                                        const line = commandLine(run);

                                        return (
                                            <tr
                                                key={run.id}
                                                className="hover:bg-panel-2/60"
                                            >
                                                <td className={tdClass}>
                                                    <Badge
                                                        tone={tone[run.status]}
                                                    >
                                                        {t(
                                                            `commands.statuses.${run.status}`,
                                                        )}
                                                    </Badge>
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'font-mono text-xs whitespace-nowrap',
                                                    )}
                                                >
                                                    {run.name}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'max-w-[48ch] font-mono text-xs break-all text-ink-2',
                                                    )}
                                                >
                                                    {line !== '' ? (
                                                        line
                                                    ) : (
                                                        <span className="text-ink-3">
                                                            {t(
                                                                'commands.runs.no_input',
                                                            )}
                                                        </span>
                                                    )}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-xs whitespace-nowrap text-ink-2',
                                                    )}
                                                >
                                                    {run.started_at && (
                                                        <time
                                                            dateTime={
                                                                run.started_at
                                                            }
                                                            title={formatDateTime(
                                                                run.started_at,
                                                            )}
                                                        >
                                                            <Relative
                                                                iso={
                                                                    run.started_at
                                                                }
                                                            />
                                                        </time>
                                                    )}
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
                                                                    'commands.runs.view_issue',
                                                                )}
                                                            </Link>
                                                        )}
                                                    </span>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </PageBody>
        </>
    );
}
