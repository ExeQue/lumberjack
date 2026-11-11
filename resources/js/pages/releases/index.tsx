import { Relative } from '@/components/lj/relative';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Card,
    EmptyState,
    PageTitle,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatDateTime,
    formatDuration,
    formatNumber,
    formatPercent,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import { show as issueShow, index as issuesIndex } from '@/routes/issues';
import { show as projectShow } from '@/routes/projects';
import { index as releasesIndex } from '@/routes/releases';
import type {
    DashboardFilters,
    IssueSummary,
    ProjectHeader,
    ReleaseStat,
    TeamShared,
} from '@/types/lumberjack';

type Filters = DashboardFilters & { release: string };

type Props = {
    project: ProjectHeader;
    filters: Filters;
    releases: ReleaseStat[];
    newIssues: IssueSummary[] | null;
};

export default function ReleasesIndex({
    project,
    filters,
    releases,
    newIssues,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;

    useProjectLive(
        project.uuid,
        ['performance.changed', 'issues.changed'],
        ['releases', 'newIssues'],
    );

    const pick = (release: string) => {
        const query = Object.fromEntries(
            Object.entries({
                ...filters,
                release: release === filters.release ? '' : release,
            }).filter(([, v]) => v !== null && v !== ''),
        ) as Record<string, string>;

        router.get(
            releasesIndex(project.uuid, { query }).url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['newIssues', 'filters'],
            },
        );
    };

    return (
        <>
            <Head title={`${t('releases.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('releases.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody>
                <PageTitle
                    title={t('releases.title')}
                    description={t('releases.description', {}, releases.length)}
                />

                <Card className="overflow-x-auto">
                    {releases.length === 0 ? (
                        <EmptyState title={t('releases.empty_title')}>
                            {t('releases.empty_body')}
                        </EmptyState>
                    ) : (
                        <table className={tableClass}>
                            <thead>
                                <tr>
                                    <th className={thClass}>
                                        {t('releases.columns.release')}
                                    </th>
                                    <th className={thClass}>
                                        {t('releases.columns.first_seen')}
                                    </th>
                                    <th className={thClass}>
                                        {t('releases.columns.last_seen')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('releases.columns.requests')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('releases.columns.error_rate')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('releases.columns.p95')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('releases.columns.occurrences')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('releases.columns.new_issues')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('releases.columns.regressions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {releases.map((r) => {
                                    const active =
                                        r.release === filters.release;
                                    const first = Date.parse(r.first_seen_at);

                                    return (
                                        <tr
                                            key={r.release}
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
                                                        pick(r.release)
                                                    }
                                                    className="lj-row-link cursor-pointer text-left font-mono text-xs text-ink hover:text-amber"
                                                >
                                                    {r.release}
                                                </button>
                                            </td>
                                            <td
                                                className={`${tdClass} whitespace-nowrap text-ink-2`}
                                            >
                                                <time
                                                    dateTime={r.first_seen_at}
                                                    title={formatDateTime(
                                                        r.first_seen_at,
                                                    )}
                                                >
                                                    <Relative
                                                        iso={r.first_seen_at}
                                                    />
                                                </time>
                                                {/* Deployed during maintenance. */}
                                                {project.maintenance.some(
                                                    (w) =>
                                                        Date.parse(
                                                            w.starts_at,
                                                        ) <= first &&
                                                        (w.ended_at
                                                            ? Date.parse(
                                                                  w.ended_at,
                                                              )
                                                            : Date.now()) >=
                                                            first,
                                                ) && (
                                                    <Badge
                                                        tone="info"
                                                        className="ml-2"
                                                    >
                                                        {t(
                                                            'health.maintenance.title',
                                                        )}
                                                    </Badge>
                                                )}
                                            </td>
                                            <td
                                                className={`${tdClass} whitespace-nowrap text-ink-2`}
                                            >
                                                <time
                                                    dateTime={r.last_seen_at}
                                                    title={formatDateTime(
                                                        r.last_seen_at,
                                                    )}
                                                >
                                                    <Relative
                                                        iso={r.last_seen_at}
                                                    />
                                                </time>
                                            </td>
                                            <td
                                                className={`${tdClass} ${numClass}`}
                                            >
                                                {formatNumber(r.requests)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                    (r.error_rate ?? 0) >
                                                        0.01 && 'text-err',
                                                )}
                                            >
                                                {r.error_rate === null
                                                    ? '-'
                                                    : formatPercent(
                                                          r.error_rate,
                                                          2,
                                                      )}
                                                <Change
                                                    current={r.error_rate}
                                                    change={r.change.error_rate}
                                                    previous={r.previous}
                                                    value={
                                                        r.previous
                                                            ?.error_rate ?? null
                                                    }
                                                />
                                            </td>
                                            <td
                                                className={`${tdClass} ${numClass}`}
                                            >
                                                {formatDuration(r.p95)}
                                                <Change
                                                    current={r.p95}
                                                    change={r.change.p95}
                                                    previous={r.previous}
                                                    value={
                                                        r.previous?.p95 ?? null
                                                    }
                                                />
                                            </td>
                                            <td
                                                className={`${tdClass} ${numClass}`}
                                            >
                                                {formatNumber(r.occurrences)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                    r.new_issues > 0
                                                        ? 'text-warn'
                                                        : 'text-ink-3',
                                                )}
                                            >
                                                {formatNumber(r.new_issues)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                    r.regressions > 0
                                                        ? 'text-err'
                                                        : 'text-ink-3',
                                                )}
                                            >
                                                {formatNumber(r.regressions)}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </Card>

                <Dialog
                    open={filters.release !== '' && !!newIssues}
                    onOpenChange={(open) => !open && pick(filters.release)}
                >
                    {filters.release !== '' && newIssues && (
                        <DialogContent
                            aria-describedby={undefined}
                            className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                        >
                            <DialogHeader>
                                <DialogTitle className="pr-6 text-sm break-words">
                                    {t('releases.detail.title', {
                                        release: filters.release,
                                    })}
                                </DialogTitle>
                                <Link
                                    href={
                                        issuesIndex(project.uuid, {
                                            query: { release: filters.release },
                                        }).url
                                    }
                                    className="text-xs text-ink-2 hover:text-amber hover:underline"
                                >
                                    {t('releases.detail.all')}
                                </Link>
                            </DialogHeader>
                            {newIssues.length === 0 ? (
                                <p className="p-4 text-ink-3">
                                    {t('releases.detail.empty')}
                                </p>
                            ) : (
                                <ul className="min-w-0">
                                    {newIssues.map((issue) => (
                                        <li
                                            key={issue.id}
                                            className="flex flex-wrap items-center justify-between gap-3 border-t border-line/60 px-4 py-2 first:border-t-0"
                                        >
                                            <Link
                                                href={
                                                    issueShow([
                                                        project.uuid,
                                                        issue.id,
                                                    ]).url
                                                }
                                                className="min-w-0 truncate text-ink hover:text-amber"
                                            >
                                                {issue.reference && (
                                                    <span className="mr-2 font-mono text-xs text-ink-3">
                                                        {issue.reference}
                                                    </span>
                                                )}
                                                {issue.title}
                                            </Link>
                                            <Badge>
                                                {t(
                                                    'releases.detail.occurrences',
                                                    {
                                                        count: formatNumber(
                                                            issue.occurrences,
                                                        ),
                                                    },
                                                    issue.occurrences,
                                                )}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </DialogContent>
                    )}
                </Dialog>
            </PageBody>
        </>
    );
}

/** The change from the release before, red when worse: higher is worse for both columns. */
function Change({
    current,
    change,
    previous,
    value,
}: {
    current: number | null;
    change: number | null;
    previous: ReleaseStat['previous'];
    value: number | null;
}) {
    const t = useT();

    if (previous === null || current === null || value === null) {
        return null;
    }

    // Nothing to divide by: errors where the release before had none.
    if (change === null) {
        return current > 0 ? (
            <span
                className="ml-1.5 text-[11px] text-err"
                title={t('releases.change.from_zero', {
                    release: previous.release,
                })}
            >
                ▲
            </span>
        ) : null;
    }

    if (Math.abs(change) < 0.005) {
        return null;
    }

    const worse = change > 0;
    const percent = formatPercent(Math.abs(change), 0);

    return (
        <span
            className={cn('ml-1.5 text-[11px]', worse ? 'text-err' : 'text-ok')}
            title={t(
                worse ? 'releases.change.worse' : 'releases.change.better',
                {
                    change: percent,
                    release: previous.release,
                },
            )}
        >
            {worse ? '▲' : '▼'} {percent}
        </span>
    );
}
