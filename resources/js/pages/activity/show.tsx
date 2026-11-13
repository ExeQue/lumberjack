import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ActivityFilters,
    filterQuery,
    parseReference,
    referenceLabel,
} from '@/components/lj/activity-filters';
import type {
    Reference,
    ReferenceFilters,
} from '@/components/lj/activity-filters';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Card,
    CardHeader,
    EmptyState,
    PageTitle,
    Segmented,
    Sparkline,
    spreadTips,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import {
    index as activityIndex,
    show as activityShow,
} from '@/routes/activity';
import { destroy as forgetActor } from '@/routes/activity/actor';
import { show as projectShow } from '@/routes/projects';
import { show as traceShow } from '@/routes/traces';
import type {
    DashboardFilters,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Sort = 'time' | 'actor';

type Occurrence = {
    id: number;
    at: string;
    actor: Reference | null;
    subjects: Reference[];
    payload: Record<string, unknown> | null;
    trace_id: string | null;
    environment: string | null;
    release: string | null;
    host: string | null;
};

type Filters = DashboardFilters & ReferenceFilters & { sort: Sort };

type Props = {
    project: ProjectHeader;
    filters: Filters;
    event: { group: string; name: string; label: string; group_label: string };
    stats: { count: number; series: number[] } | null;
    occurrences: Occurrence[];
    next_url: string | null;
    prev_url: string | null;
    can_forget: boolean;
};

const pagerClass =
    'inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 text-ink';

export default function ActivityShow({
    project,
    filters,
    event,
    stats,
    occurrences,
    next_url,
    prev_url,
    can_forget,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const raw = `${event.group}:${event.name}`;
    const title = `${event.group_label}: ${event.label}`;
    // Forgetting is for one actor at a time.
    const actor =
        filters.actors.length === 1 ? parseReference(filters.actors[0]) : null;

    useProjectLive(
        project.uuid,
        ['activity.changed'],
        ['stats', 'occurrences', 'next_url', 'prev_url'],
    );

    const apply = (changes: Partial<Filters>) => {
        const query = filterQuery({ ...filters, ...changes });

        router.get(
            activityShow([project.uuid, event.group, event.name], { query })
                .url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const referenceButton = (
        reference: Reference | null,
        kind: 'actor' | 'subject',
    ) =>
        reference === null ? (
            <span className="text-ink-3">-</span>
        ) : (
            <button
                type="button"
                onClick={() => {
                    const key = `${reference.type}:${reference.id}`;
                    const list =
                        kind === 'actor' ? filters.actors : filters.subjects;

                    if (!list.includes(key)) {
                        apply(
                            kind === 'actor'
                                ? { actors: [...list, key] }
                                : { subjects: [...list, key] },
                        );
                    }
                }}
                title={t(`activity.filter_${kind}`, {
                    reference: referenceLabel(reference),
                })}
                className="cursor-pointer text-left font-mono text-xs hover:text-amber hover:underline"
            >
                {referenceLabel(reference)}
            </button>
        );

    return (
        <>
            <Head title={`${title} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    {
                        label: t('activity.title'),
                        href: activityIndex(project.uuid).url,
                    },
                    { label: title },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody>
                <PageTitle
                    title={
                        <span>
                            {title}
                            <span className="ml-2 font-mono text-sm font-normal text-ink-3">
                                {raw}
                            </span>
                        </span>
                    }
                    description={t(
                        'activity.occurrences_description',
                        { count: formatNumber(stats?.count ?? 0) },
                        stats?.count ?? 0,
                    )}
                    actions={
                        stats && (
                            <Sparkline
                                values={stats.series}
                                tips={spreadTips(
                                    stats.series,
                                    filters.period,
                                    formatNumber,
                                )}
                                color="var(--lj-info)"
                                width={200}
                                height={36}
                                label={t('activity.trend_label', {
                                    count: formatNumber(stats.count),
                                })}
                            />
                        )
                    }
                />

                <Card aria-labelledby="occurrences-title">
                    <CardHeader
                        id="occurrences-title"
                        title={t('activity.occurrences')}
                    />
                    <div className="flex flex-wrap items-center gap-3 border-b border-line/60 px-3 py-2.5">
                        <Segmented<Sort>
                            label={t('activity.sort.label')}
                            value={filters.sort}
                            options={[
                                {
                                    value: 'time',
                                    label: t('activity.sort.time'),
                                },
                                {
                                    value: 'actor',
                                    label: t('activity.sort.actor'),
                                },
                            ]}
                            onChange={(sort) => apply({ sort })}
                        />
                        <ActivityFilters
                            filters={filters}
                            onChange={apply}
                            project={project.uuid}
                            event={event}
                        />
                        {actor && can_forget && (
                            <div className="ml-auto">
                                <ConfirmButton
                                    question={t('activity.forget_question', {
                                        reference: referenceLabel(actor),
                                    })}
                                    confirmLabel={t('activity.forget')}
                                    onConfirm={() =>
                                        router.delete(
                                            forgetActor(project.uuid).url,
                                            {
                                                data: actor,
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    apply({ actors: [] }),
                                            },
                                        )
                                    }
                                >
                                    {t('activity.forget')}
                                </ConfirmButton>
                            </div>
                        )}
                    </div>
                    {occurrences.length === 0 ? (
                        <EmptyState title={t('activity.no_occurrences')} />
                    ) : (
                        <div className="relative overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('activity.columns.time')}
                                        </th>
                                        <th className={thClass}>
                                            {t('activity.columns.actor')}
                                        </th>
                                        <th className={thClass}>
                                            {t('activity.columns.subjects')}
                                        </th>
                                        <th className={thClass}>
                                            {t('activity.columns.payload')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'hidden lg:table-cell',
                                            )}
                                        >
                                            {t('activity.columns.release')}
                                        </th>
                                        <th className={thClass}>
                                            <span className="sr-only">
                                                {t('traces.open')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {occurrences.map((o) => (
                                        <tr
                                            key={o.id}
                                            className="align-top hover:bg-panel-2/60"
                                        >
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'font-mono text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                <time dateTime={o.at}>
                                                    {formatDateTime(o.at)}
                                                </time>
                                            </td>
                                            <td className={tdClass}>
                                                {referenceButton(
                                                    o.actor,
                                                    'actor',
                                                )}
                                            </td>
                                            <td className={tdClass}>
                                                {o.subjects.length === 0 ? (
                                                    <span className="text-ink-3">
                                                        -
                                                    </span>
                                                ) : (
                                                    <ul className="flex flex-col gap-0.5">
                                                        {o.subjects.map(
                                                            (subject) => (
                                                                <li
                                                                    key={`${subject.type}:${subject.id}`}
                                                                >
                                                                    {referenceButton(
                                                                        subject,
                                                                        'subject',
                                                                    )}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'max-w-[60ch] min-w-48',
                                                )}
                                            >
                                                {o.payload === null ? (
                                                    <span className="text-ink-3">
                                                        -
                                                    </span>
                                                ) : o.payload._truncated ===
                                                  true ? (
                                                    <span className="text-warn">
                                                        {t(
                                                            'activity.payload_truncated',
                                                        )}
                                                    </span>
                                                ) : (
                                                    <details>
                                                        <summary className="cursor-pointer truncate font-mono text-xs text-ink-2">
                                                            {JSON.stringify(
                                                                o.payload,
                                                            )}
                                                        </summary>
                                                        <pre className="mt-2 overflow-x-auto rounded-md bg-panel-2 p-2 font-mono text-xs whitespace-pre-wrap">
                                                            {JSON.stringify(
                                                                o.payload,
                                                                null,
                                                                2,
                                                            )}
                                                        </pre>
                                                    </details>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'hidden font-mono text-xs text-ink-2 lg:table-cell',
                                                )}
                                            >
                                                {o.release ?? '-'}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-right',
                                                )}
                                            >
                                                {o.trace_id && (
                                                    <Link
                                                        href={
                                                            traceShow([
                                                                project.uuid,
                                                                o.trace_id,
                                                            ]).url
                                                        }
                                                        className="inline-block py-1 text-xs whitespace-nowrap text-ink-2 hover:text-amber hover:underline"
                                                    >
                                                        {t('traces.open')}
                                                    </Link>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                {(prev_url || next_url) && (
                    <nav
                        aria-label={t('activity.pages')}
                        className="flex justify-end gap-2"
                    >
                        {prev_url && (
                            <Link href={prev_url} className={pagerClass}>
                                {t('activity.newer')}
                            </Link>
                        )}
                        {next_url && (
                            <Link href={next_url} className={pagerClass}>
                                {t('activity.older')}
                            </Link>
                        )}
                    </nav>
                )}
            </PageBody>
        </>
    );
}
