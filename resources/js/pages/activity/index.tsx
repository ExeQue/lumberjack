import { Head, Link, router, usePage } from '@inertiajs/react';
import { ActivityFilters, filterQuery } from '@/components/lj/activity-filters';
import type { ReferenceFilters } from '@/components/lj/activity-filters';
import { Relative } from '@/components/lj/relative';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Card,
    CardHeader,
    EmptyState,
    PageTitle,
    Sparkline,
    numClass,
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
import { show as projectShow } from '@/routes/projects';
import type {
    DashboardFilters,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type EventStat = {
    group: string;
    name: string;
    count: number;
    last_at: string | null;
    series: number[];
};

type Filters = DashboardFilters & ReferenceFilters;

type Props = {
    project: ProjectHeader;
    filters: Filters;
    activities: EventStat[];
};

export default function ActivityIndex({ project, filters, activities }: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;

    useProjectLive(project.uuid, ['activity.changed'], ['activities']);

    // By group in name order, each group's activity most frequent first.
    const groups = Object.entries(
        activities.reduce<Record<string, EventStat[]>>((acc, event) => {
            (acc[event.group] ??= []).push(event);

            return acc;
        }, {}),
    ).toSorted(([a], [b]) => a.localeCompare(b));

    const query = filterQuery(filters);

    const apply = (changes: Partial<Filters>) => {
        const next = filterQuery({ ...filters, ...changes });

        router.get(
            activityIndex(project.uuid, { query: next }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`${t('activity.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('activity.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody>
                <PageTitle
                    title={t('activity.title')}
                    description={t(
                        'activity.description',
                        {},
                        activities.length,
                    )}
                />

                <ActivityFilters
                    filters={filters}
                    onChange={apply}
                    project={project.uuid}
                />

                {groups.length === 0 ? (
                    <Card>
                        <EmptyState title={t('activity.empty_title')}>
                            {t('activity.empty_body')}
                        </EmptyState>
                    </Card>
                ) : (
                    groups.map(([group, rows]) => (
                        <Card
                            key={group}
                            aria-labelledby={`activity-group-${group}`}
                        >
                            <CardHeader
                                id={`activity-group-${group}`}
                                title={group}
                            />
                            <div className="relative overflow-x-auto">
                                <table className={tableClass}>
                                    <thead>
                                        <tr>
                                            <th className={thClass}>
                                                {t('activity.columns.event')}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'text-right',
                                                )}
                                            >
                                                {t('activity.columns.count')}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'hidden md:table-cell',
                                                )}
                                            >
                                                {t('activity.columns.trend')}
                                            </th>
                                            <th className={thClass}>
                                                {t(
                                                    'activity.columns.last_seen',
                                                )}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((event) => (
                                            <tr
                                                key={event.name}
                                                className="lj-row hover:bg-panel-2/60"
                                            >
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'font-mono text-xs',
                                                    )}
                                                >
                                                    <Link
                                                        href={
                                                            activityShow(
                                                                [
                                                                    project.uuid,
                                                                    event.group,
                                                                    event.name,
                                                                ],
                                                                { query },
                                                            ).url
                                                        }
                                                        className="lj-row-link hover:text-amber"
                                                    >
                                                        {event.name}
                                                    </Link>
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        numClass,
                                                    )}
                                                >
                                                    {formatNumber(event.count)}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'hidden md:table-cell',
                                                    )}
                                                >
                                                    <Sparkline
                                                        values={event.series}
                                                        tips={spreadTips(
                                                            event.series,
                                                            filters.period,
                                                            formatNumber,
                                                        )}
                                                        color="var(--lj-info)"
                                                        label={t(
                                                            'events.trend_label',
                                                            {
                                                                count: formatNumber(
                                                                    event.count,
                                                                ),
                                                            },
                                                        )}
                                                    />
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'text-xs whitespace-nowrap text-ink-2',
                                                    )}
                                                >
                                                    {event.last_at && (
                                                        <time
                                                            dateTime={
                                                                event.last_at
                                                            }
                                                            title={formatDateTime(
                                                                event.last_at,
                                                            )}
                                                        >
                                                            <Relative
                                                                iso={
                                                                    event.last_at
                                                                }
                                                            />
                                                        </time>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card>
                    ))
                )}
            </PageBody>
        </>
    );
}
