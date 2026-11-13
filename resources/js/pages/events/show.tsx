import { Head, Link, router, usePage } from '@inertiajs/react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Button,
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
import { index as eventsIndex, show as eventShow } from '@/routes/events';
import { destroy as forgetActor } from '@/routes/events/actor';
import { show as projectShow } from '@/routes/projects';
import { show as traceShow } from '@/routes/traces';
import type {
    DashboardFilters,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Sort = 'time' | 'actor';

type Reference = { type: string; id: string };

type Occurrence = {
    id: number;
    at: string;
    actor: Reference | null;
    subject: Reference | null;
    payload: Record<string, unknown> | null;
    trace_id: string | null;
    environment: string | null;
    release: string | null;
    host: string | null;
};

type Filters = DashboardFilters & {
    sort: Sort;
    actor_type: string | null;
    actor_id: string | null;
    subject_type: string | null;
    subject_id: string | null;
};

type Props = {
    project: ProjectHeader;
    filters: Filters;
    event: { group: string; name: string };
    stats: { count: number; series: number[] } | null;
    occurrences: Occurrence[];
    next_url: string | null;
    prev_url: string | null;
    can_forget: boolean;
};

const pagerClass =
    'inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 text-ink';

/** "user #17": a type's last segment (App\Models\User is user) and its key. */
function referenceLabel(reference: Reference): string {
    const type = reference.type.split('\\').pop() ?? reference.type;

    return `${type} #${reference.id}`;
}

export default function EventShow({
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
    const title = `${event.group}: ${event.name}`;
    const actor =
        filters.actor_type && filters.actor_id
            ? { type: filters.actor_type, id: filters.actor_id }
            : null;
    const subject =
        filters.subject_type && filters.subject_id
            ? { type: filters.subject_type, id: filters.subject_id }
            : null;

    useProjectLive(
        project.uuid,
        ['activity.changed'],
        ['stats', 'occurrences', 'next_url', 'prev_url'],
    );

    const apply = (changes: Partial<Filters>) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(
                ([key, v]) =>
                    v !== null && v !== '' && !(key === 'sort' && v === 'time'),
            ),
        ) as Record<string, string>;

        router.get(
            eventShow([project.uuid, event.group, event.name], { query }).url,
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
                onClick={() =>
                    apply({
                        [`${kind}_type`]: reference.type,
                        [`${kind}_id`]: reference.id,
                    })
                }
                title={t(`events.filter_${kind}`, {
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
                        label: t('events.title'),
                        href: eventsIndex(project.uuid).url,
                    },
                    { label: title, mono: true },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody>
                <PageTitle
                    title={<span className="font-mono">{title}</span>}
                    description={t(
                        'events.occurrences_description',
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
                                label={t('events.trend_label', {
                                    count: formatNumber(stats.count),
                                })}
                            />
                        )
                    }
                />

                <Card aria-labelledby="occurrences-title">
                    <CardHeader
                        id="occurrences-title"
                        title={t('events.occurrences')}
                    />
                    <div className="flex flex-wrap items-center gap-3 border-b border-line/60 px-3 py-2.5">
                        <Segmented<Sort>
                            label={t('events.sort.label')}
                            value={filters.sort}
                            options={[
                                { value: 'time', label: t('events.sort.time') },
                                {
                                    value: 'actor',
                                    label: t('events.sort.actor'),
                                },
                            ]}
                            onChange={(sort) => apply({ sort })}
                        />
                        {actor && (
                            <Button
                                onClick={() =>
                                    apply({ actor_type: null, actor_id: null })
                                }
                            >
                                {t('events.clear_actor', {
                                    reference: referenceLabel(actor),
                                })}
                            </Button>
                        )}
                        {subject && (
                            <Button
                                onClick={() =>
                                    apply({
                                        subject_type: null,
                                        subject_id: null,
                                    })
                                }
                            >
                                {t('events.clear_subject', {
                                    reference: referenceLabel(subject),
                                })}
                            </Button>
                        )}
                        {actor && can_forget && (
                            <div className="ml-auto">
                                <ConfirmButton
                                    question={t('events.forget_question', {
                                        reference: referenceLabel(actor),
                                    })}
                                    confirmLabel={t('events.forget')}
                                    onConfirm={() =>
                                        router.delete(
                                            forgetActor(project.uuid).url,
                                            {
                                                data: actor,
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    apply({
                                                        actor_type: null,
                                                        actor_id: null,
                                                    }),
                                            },
                                        )
                                    }
                                >
                                    {t('events.forget')}
                                </ConfirmButton>
                            </div>
                        )}
                    </div>
                    {occurrences.length === 0 ? (
                        <EmptyState title={t('events.no_occurrences')} />
                    ) : (
                        <div className="relative overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('events.columns.time')}
                                        </th>
                                        <th className={thClass}>
                                            {t('events.columns.actor')}
                                        </th>
                                        <th className={thClass}>
                                            {t('events.columns.subject')}
                                        </th>
                                        <th className={thClass}>
                                            {t('events.columns.payload')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'hidden lg:table-cell',
                                            )}
                                        >
                                            {t('events.columns.release')}
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
                                                {referenceButton(
                                                    o.subject,
                                                    'subject',
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
                                                            'events.payload_truncated',
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
                        aria-label={t('events.pages')}
                        className="flex justify-end gap-2"
                    >
                        {prev_url && (
                            <Link href={prev_url} className={pagerClass}>
                                {t('events.newer')}
                            </Link>
                        )}
                        {next_url && (
                            <Link href={next_url} className={pagerClass}>
                                {t('events.older')}
                            </Link>
                        )}
                    </nav>
                )}
            </PageBody>
        </>
    );
}
