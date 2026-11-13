import { Deferred, Head, router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { LabelEditor } from '@/components/lj/label-editor';
import {
    ConnectionCard,
    ConnectorPanel,
    ConnectorTabs,
    useHashTab,
    useTrend,
} from '@/components/lj/connectors/connection';
import { TopBar } from '@/components/lj/top-bar';
import {
    UmamiConnectDialog,
    UmamiWebsiteSwitcher,
} from '@/components/lj/connectors/umami/connect';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    PageTitle,
    Segmented,
    Select,
    StatCard,
    TimeBars,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { Skeleton } from '@/components/ui/skeleton';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatCompact,
    formatDate,
    formatDateTime,
    formatDuration,
    formatNumber,
    formatPercent,
    locale,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as projectShow } from '@/routes/projects';
import { index as umamiIndex } from '@/routes/connectors/umami';
import {
    destroy as annotationsDestroy,
    store as annotationsStore,
} from '@/routes/connectors/umami/annotations';
import { update as utmLabelsUpdate } from '@/routes/connectors/umami/utm-labels';
import type {
    DashboardFilters,
    Period,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Figures = {
    visitors: number;
    visits: number;
    pageviews: number;
    bounce_rate: number | null;
    visit_duration: number | null;
};

type Row = {
    name: string;
    visitors: number;
    visits: number;
    pageviews: number;
    bounce_rate: number | null;
    visit_duration: number | null;
    /** Pages only: LCP p75 as Lumberjack's browser script measured the same page. */
    lcp_us?: number | null;
    /** UTM values only: the name it was given here. */
    label?: string | null;
    /** Pages only: the domain it was seen on. */
    host?: string | null;
};

/** Names a UTM value, or forgets its name when given an empty one. */
type SaveLabel = (dimension: string, value: string, label: string) => void;

type CardRows = Record<string, Row[]>;

type Props = {
    project: ProjectHeader;
    filters: DashboardFilters & { filter: Record<string, string> };
    /** What each breakdown card was searched for, by card. */
    searches: Record<string, string>;
    /** How many rows each breakdown card shows, when more than the first ten. */
    rows: Record<string, number>;
    connection: {
        uuid: string;
        website_id: string;
        website_name: string | null;
        hosting: 'cloud' | 'self_hosted';
        instance: string | null;
        timezone: string;
    } | null;
    /** Every website the project reads, by the uuid of its connection. */
    websites: { uuid: string; name: string }[];
    dimensions: Record<string, string | null>;
    error: string | null;
    summary: { current: Figures; previous: Figures } | null;
    active: number | null;
    series: { t: string; visitors: number; pageviews: number }[];
    releases: { release: string; at: string }[];
    /** Notes kept in Umami, each at the moment it marks; an all-day one at its day's start. */
    annotations: { id: string; at: string; all_day: boolean; note: string }[];
    /** Visitors per weekday (Monday first) and hour over the last four weeks. */
    heatmap?: number[][];
    pages?: CardRows;
    sources?: CardRows;
    audience?: CardRows;
    events?: CardRows;
};

const PERIODS: Period[] = ['1h', '24h', '7d', '30d'];

/** Rows a breakdown shows at first and adds per Load more, and the most it loads. */
const ROWS = 10;
const MAX_ROWS = 200;

/** Google's good and poor LCP thresholds, in µs. */
const LCP = [2_500_000, 4_000_000];

export default function UmamiIndex(props: Props) {
    const { project, connection, error, filters } = props;
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;

    /**
     * Visits the page as changed: the website in the path, and only what differs from the
     * defaults in the query, so the URL stays short. A card's search and rows are kept until
     * the website, period or filters change.
     */
    const visit = (
        changes: {
            period?: Period;
            filter?: Record<string, string>;
            connection?: string;
            searches?: Record<string, string>;
            rows?: Record<string, number>;
        },
        only?: string[],
    ) => {
        const resets =
            changes.connection !== undefined ||
            changes.period !== undefined ||
            changes.filter !== undefined;
        const query: Record<string, string> = {};
        const period = changes.period ?? filters.period;

        if (period !== '24h') {
            query.period = period;
        }

        Object.entries(changes.filter ?? filters.filter).forEach(
            ([key, value]) => (query[`filter[${key}]`] = value),
        );
        Object.entries(
            changes.searches ?? (resets ? {} : props.searches),
        ).forEach(([card, text]) => {
            if (text.trim() !== '') {
                query[`search[${card}]`] = text.trim();
            }
        });
        Object.entries(changes.rows ?? (resets ? {} : props.rows)).forEach(
            ([card, count]) => (query[`rows[${card}]`] = String(count)),
        );

        const website = changes.connection ?? connection?.uuid;
        const url = website
            ? umamiIndex([project.uuid, website], { query }).url
            : umamiIndex({ project: project.uuid }, { query }).url;

        router.get(
            url + window.location.hash,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                ...(only ? { only } : {}),
            },
        );
    };

    const saveLabel: SaveLabel | undefined =
        project.can_administer && connection
            ? (dimension, value, label) =>
                  router.put(
                      utmLabelsUpdate([project.uuid, connection.uuid]).url,
                      { dimension, value, label },
                      {
                          preserveScroll: true,
                          preserveState: true,
                          only: ['sources'],
                      },
                  )
            : undefined;

    const [tab, setTab] = useHashTab<Tab>(TABS);

    const addFilter = (values: Record<string, string>) =>
        visit({ filter: { ...filters.filter, ...values } });

    return (
        <>
            <Head title={`${t('connectors.umami.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('connectors.umami.title') },
                ]}
            >
                {connection && (
                    <Segmented<Period>
                        label={t('common.filters.period')}
                        value={filters.period}
                        onChange={(period) => visit({ period })}
                        options={PERIODS.map((period) => ({
                            value: period,
                            label: t(`common.periods.${period}`),
                        }))}
                    />
                )}
            </TopBar>
            <PageBody>
                <PageTitle
                    title={t('connectors.umami.title')}
                    description={
                        connection
                            ? t('connectors.umami.description', {
                                  website:
                                      connection.website_name ??
                                      connection.website_id,
                              })
                            : t('connectors.umami.not_connected.description')
                    }
                    actions={
                        <span className="flex flex-wrap items-center gap-2">
                            {props.active !== null && (
                                <Badge tone="ok">
                                    {t(
                                        'connectors.umami.active',
                                        { count: formatNumber(props.active) },
                                        props.active,
                                    )}
                                </Badge>
                            )}
                            {connection && props.websites.length > 1 && (
                                <Select
                                    aria-label={t('connectors.umami.website')}
                                    value={connection.uuid}
                                    className="w-auto"
                                    onChange={(e) =>
                                        visit({
                                            connection: e.target.value,
                                            filter: {},
                                        })
                                    }
                                >
                                    {props.websites.map((website) => (
                                        <option
                                            key={website.uuid}
                                            value={website.uuid}
                                        >
                                            {website.name}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </span>
                    }
                />

                {connection === null ? (
                    <NotConnected project={project} />
                ) : (
                    <>
                        <Filters
                            filter={filters.filter}
                            onRemove={(key) => {
                                const rest = { ...filters.filter };
                                delete rest[key];
                                visit({ filter: rest });
                            }}
                        />
                        {props.summary && error === null && (
                            <Summary summary={props.summary} />
                        )}
                        <ConnectorTabs<Tab>
                            id="umami"
                            label={t('connectors.umami.tabs.label')}
                            tabs={TABS.filter((name) =>
                                name === 'connection'
                                    ? project.can_administer
                                    : name === 'overview' ||
                                      props.summary !== null,
                            )}
                            current={tab}
                            onChange={setTab}
                            title={(name) => t(`connectors.umami.tabs.${name}`)}
                        />
                        <ConnectorPanel
                            id="umami"
                            name="overview"
                            current={tab}
                        >
                            {error !== null ? (
                                <Card>
                                    <EmptyState
                                        title={t(
                                            'connectors.umami.error_title',
                                        )}
                                    >
                                        <p>{error}</p>
                                    </EmptyState>
                                </Card>
                            ) : (
                                props.summary && (
                                    <>
                                        <Traffic
                                            project={project}
                                            connection={connection.uuid}
                                            series={props.series}
                                            releases={props.releases}
                                            annotations={props.annotations}
                                            period={filters.period}
                                        />
                                        <Heatmap grid={props.heatmap} />
                                    </>
                                )
                            )}
                        </ConnectorPanel>
                        {props.summary !== null &&
                            (
                                [
                                    'pages',
                                    'sources',
                                    'audience',
                                    'events',
                                ] as const
                            ).map((card) => (
                                <ConnectorPanel
                                    key={card}
                                    id="umami"
                                    name={card}
                                    current={tab}
                                >
                                    <Breakdown
                                        card={card}
                                        rows={props[card]}
                                        total={props.summary!.current.visitors}
                                        dimensions={props.dimensions}
                                        searches={props.searches}
                                        shown={props.rows}
                                        onChange={visit}
                                        onFilter={addFilter}
                                        onLabel={
                                            card === 'sources'
                                                ? saveLabel
                                                : undefined
                                        }
                                    />
                                </ConnectorPanel>
                            ))}
                        {project.can_administer && (
                            <ConnectorPanel
                                id="umami"
                                name="connection"
                                current={tab}
                            >
                                <Connection
                                    project={project.uuid}
                                    connection={connection}
                                />
                            </ConnectorPanel>
                        )}
                    </>
                )}
            </PageBody>
        </>
    );
}

const TABS = [
    'overview',
    'pages',
    'sources',
    'audience',
    'events',
    'connection',
] as const;

type Tab = (typeof TABS)[number];

function NotConnected({ project }: { project: ProjectHeader }) {
    const t = useT();
    const [connecting, setConnecting] = useState(false);

    return (
        <Card>
            <EmptyState title={t('connectors.umami.not_connected.title')}>
                <p>
                    {project.can_administer
                        ? t('connectors.umami.not_connected.body')
                        : t('connectors.umami.not_connected.members')}
                </p>
                {project.can_administer && (
                    <Button
                        variant="primary"
                        className="mt-3"
                        onClick={() => setConnecting(true)}
                    >
                        {t('connectors.umami.connect.open')}
                    </Button>
                )}
            </EmptyState>
            {connecting && (
                <UmamiConnectDialog
                    project={project.uuid}
                    onClose={() => setConnecting(false)}
                />
            )}
        </Card>
    );
}

function Filters({
    filter,
    onRemove,
}: {
    filter: Record<string, string>;
    onRemove: (key: string) => void;
}) {
    const t = useT();
    const entries = Object.entries(filter);

    if (entries.length === 0) {
        return null;
    }

    return (
        <ul
            aria-label={t('connectors.umami.filters.label')}
            className="flex flex-wrap items-center gap-2"
        >
            {entries.map(([key, value]) => (
                <li key={key}>
                    <button
                        type="button"
                        onClick={() => onRemove(key)}
                        className="inline-flex items-center gap-1.5 rounded-md border border-line-strong bg-panel-2 px-2.5 py-1 text-xs hover:bg-line"
                        aria-label={t('connectors.umami.filters.remove', {
                            filter: `${t(`connectors.umami.dimensions.${key}`)}: ${value}`,
                        })}
                    >
                        <span className="text-ink-3">
                            {t(`connectors.umami.dimensions.${key}`)}
                        </span>
                        <span className="font-mono">{value}</span>
                        <span aria-hidden="true">×</span>
                    </button>
                </li>
            ))}
        </ul>
    );
}

/** "12 % more than the period before", or nothing without an earlier value. */
/** Umami counts time in whole seconds; a visit of one page has none. */
const seconds = (value: number | null) =>
    value === null
        ? '-'
        : value < 1
          ? `${formatNumber(0)} s`
          : formatDuration(value * 1_000_000);

const percent = (value: number | null) =>
    value === null ? '-' : formatPercent(value / 100, 0);

function Summary({ summary }: { summary: NonNullable<Props['summary']> }) {
    const t = useT();
    const trend = useTrend();
    const { current, previous } = summary;
    const perVisit = (f: Figures) =>
        f.visits > 0 ? f.pageviews / f.visits : null;

    return (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,160px),1fr))] gap-4">
            <StatCard
                label={t('connectors.umami.cards.visitors')}
                value={formatCompact(current.visitors)}
                note={trend(current.visitors, previous.visitors)}
            />
            <StatCard
                label={t('connectors.umami.cards.visits')}
                value={formatCompact(current.visits)}
                note={trend(current.visits, previous.visits)}
            />
            <StatCard
                label={t('connectors.umami.cards.pageviews')}
                value={formatCompact(current.pageviews)}
                note={trend(current.pageviews, previous.pageviews)}
            />
            <StatCard
                label={t('connectors.umami.cards.per_visit')}
                value={
                    perVisit(current) === null
                        ? '-'
                        : formatNumber(perVisit(current) as number, 1)
                }
                note={trend(perVisit(current), perVisit(previous))}
            />
            <StatCard
                label={t('connectors.umami.cards.bounce_rate')}
                value={percent(current.bounce_rate)}
                note={trend(current.bounce_rate, previous.bounce_rate)}
            />
            <StatCard
                label={t('connectors.umami.cards.visit_duration')}
                value={seconds(current.visit_duration)}
                note={trend(current.visit_duration, previous.visit_duration)}
            />
        </div>
    );
}

function Traffic({
    project,
    connection,
    series,
    releases,
    annotations,
    period,
}: {
    project: ProjectHeader;
    connection: string;
    series: Props['series'];
    releases: Props['releases'];
    annotations: Props['annotations'];
    period: Period;
}) {
    const t = useT();
    const [metric, setMetric] = useState<'visitors' | 'pageviews'>('visitors');
    const points = series.map((p) => ({ t: p.t, v: p[metric] }));
    const start = series.length > 0 ? Date.parse(series[0].t) : 0;
    const step =
        series.length > 1 ? Date.parse(series[1].t) - start : 3_600_000;
    const end = start + step * series.length;
    // Placed by time across the bars, so a mark lines up with the traffic after it.
    const marks = [
        ...releases.map((r) => ({
            key: `release-${r.release}`,
            kind: 'release' as const,
            text: r.release,
            iso: r.at,
        })),
        ...annotations.map((a) => ({
            key: `note-${a.id}`,
            kind: 'note' as const,
            text: a.note,
            iso: a.at,
        })),
    ]
        .map((m) => ({ ...m, at: (Date.parse(m.iso) - start) / (end - start) }))
        .filter((m) => m.at >= 0 && m.at <= 1);

    return (
        <Card aria-labelledby="umami-traffic">
            <CardHeader
                id="umami-traffic"
                title={t('connectors.umami.traffic.title')}
                description={t('connectors.umami.traffic.description')}
                aside={
                    <Segmented<'visitors' | 'pageviews'>
                        label={t('connectors.umami.traffic.metric')}
                        value={metric}
                        onChange={setMetric}
                        options={[
                            {
                                value: 'visitors',
                                label: t('connectors.umami.cards.visitors'),
                            },
                            {
                                value: 'pageviews',
                                label: t('connectors.umami.cards.pageviews'),
                            },
                        ]}
                    />
                }
            />
            <div className="p-4">
                <div className="relative">
                    <TimeBars
                        series={points}
                        period={period}
                        color="var(--lj-info)"
                        height={140}
                        formatValue={(v) => formatCompact(v)}
                        label={t('connectors.umami.traffic.summary', {
                            metric: t(`connectors.umami.cards.${metric}`),
                            total: formatNumber(
                                points.reduce((sum, p) => sum + p.v, 0),
                            ),
                        })}
                    />
                    {/* A release or a note as a line over the bars, named at its top. */}
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute top-0 right-0 left-12"
                        style={{ height: 140 }}
                    >
                        {marks.map((mark) => (
                            <span
                                key={mark.key}
                                className={cn(
                                    'absolute inset-y-0 border-l border-dashed',
                                    mark.kind === 'release'
                                        ? 'border-amber'
                                        : 'border-ok',
                                )}
                                style={{ left: `${mark.at * 100}%` }}
                            >
                                <span
                                    className={cn(
                                        'absolute left-1 max-w-40 truncate rounded-sm bg-panel px-1 text-[10px] whitespace-nowrap',
                                        mark.kind === 'release'
                                            ? '-top-0.5 font-mono text-amber'
                                            : 'top-4 text-ok',
                                    )}
                                >
                                    {mark.text}
                                </span>
                            </span>
                        ))}
                    </div>
                </div>
                {marks.length > 0 && (
                    <ul className="sr-only">
                        {marks.map((mark) => (
                            <li key={mark.key}>
                                {t(
                                    mark.kind === 'release'
                                        ? 'connectors.umami.traffic.release'
                                        : 'connectors.umami.traffic.note',
                                    {
                                        release: mark.text,
                                        note: mark.text,
                                        at: formatDateTime(mark.iso),
                                    },
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <Annotations
                project={project}
                connection={connection}
                annotations={annotations}
            />
        </Card>
    );
}

/**
 * The period's notes, such as a campaign's launch, kept in Umami so its own charts show
 * them too; added and removed by those who administer the project.
 */
function Annotations({
    project,
    connection,
    annotations,
}: {
    project: ProjectHeader;
    connection: string;
    annotations: Props['annotations'];
}) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({
        date: new Date().toISOString().slice(0, 10),
        time: '',
        note: '',
    });

    if (annotations.length === 0 && !project.can_administer) {
        return null;
    }

    return (
        <div className="border-t border-line px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-xs font-medium text-ink-2">
                    {t('connectors.umami.notes.title')}
                </h3>
                {project.can_administer && !adding && (
                    <Button onClick={() => setAdding(true)}>
                        {t('connectors.umami.notes.add')}
                    </Button>
                )}
            </div>
            {annotations.length === 0 ? (
                <p className="mt-1 text-xs text-ink-3">
                    {t('connectors.umami.notes.empty')}
                </p>
            ) : (
                <ul className="mt-2 flex flex-col gap-1">
                    {annotations.map((a) => (
                        <li
                            key={a.id}
                            className="flex items-center justify-between gap-3 text-xs"
                        >
                            <span className="min-w-0">
                                <span className="mr-2 font-mono text-ink-3">
                                    {a.all_day
                                        ? formatDate(a.at)
                                        : formatDateTime(a.at)}
                                </span>
                                <span className="text-ok">{a.note}</span>
                            </span>
                            {project.can_administer && (
                                <ConfirmButton
                                    question={t(
                                        'connectors.umami.notes.delete_question',
                                        { note: a.note },
                                    )}
                                    confirmLabel={t(
                                        'connectors.umami.notes.delete',
                                    )}
                                    onConfirm={() =>
                                        router.delete(
                                            annotationsDestroy([
                                                project.uuid,
                                                connection,
                                                a.id,
                                            ]).url,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {t('connectors.umami.notes.delete')}
                                </ConfirmButton>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {adding && (
                <form
                    className="mt-3 flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            annotationsStore([project.uuid, connection]).url,
                            form,
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setAdding(false);
                                    setForm({ ...form, time: '', note: '' });
                                },
                            },
                        );
                    }}
                >
                    <Field
                        label={t('connectors.umami.notes.date')}
                        htmlFor="umami-note-date"
                        error={errors.date}
                    >
                        <Input
                            id="umami-note-date"
                            type="date"
                            required
                            value={form.date}
                            onChange={(e) =>
                                setForm({ ...form, date: e.target.value })
                            }
                        />
                    </Field>
                    <Field
                        label={t('connectors.umami.notes.time')}
                        htmlFor="umami-note-time"
                        error={errors.time}
                    >
                        <Input
                            id="umami-note-time"
                            type="time"
                            value={form.time}
                            onChange={(e) =>
                                setForm({ ...form, time: e.target.value })
                            }
                        />
                    </Field>
                    <Field
                        label={t('connectors.umami.notes.note')}
                        htmlFor="umami-note-text"
                        error={errors.note}
                        className="min-w-60 flex-1"
                    >
                        <Input
                            id="umami-note-text"
                            required
                            maxLength={200}
                            placeholder={t(
                                'connectors.umami.notes.placeholder',
                            )}
                            value={form.note}
                            onChange={(e) =>
                                setForm({ ...form, note: e.target.value })
                            }
                        />
                    </Field>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => setAdding(false)}
                    >
                        {t('connectors.umami.connect.cancel')}
                    </Button>
                    <Button type="submit" variant="primary">
                        {t('connectors.umami.notes.save')}
                    </Button>
                </form>
            )}
        </div>
    );
}

function Breakdown({
    card,
    rows,
    total,
    dimensions,
    onFilter,
    onLabel,
    searches,
    shown,
    onChange,
}: {
    card: 'pages' | 'sources' | 'audience' | 'events';
    /** What each card's rows were searched for. */
    searches: Record<string, string>;
    /** How many rows each card shows, past the first ten. */
    shown: Record<string, number>;
    /** Loads the card again, searched or with more rows. */
    onChange: (
        changes: {
            searches?: Record<string, string>;
            rows?: Record<string, number>;
        },
        only: string[],
    ) => void;
    rows: CardRows | undefined;
    total: number;
    dimensions: Props['dimensions'];
    onFilter: (values: Record<string, string>) => void;
    onLabel?: SaveLabel;
}) {
    const t = useT();
    const id = `umami-${card}`;
    const searched = searches[card] ?? '';
    const limit = shown[card] ?? ROWS;
    const [tab, setTab] = useState<string | null>(null);
    const [search, setSearch] = useState(searched);
    const tabs = rows ? Object.keys(rows) : [];
    const current = tab ?? tabs[0];

    // Umami searches all of the period's values, not only the rows shown; asked once typing pauses.
    useEffect(() => {
        if (search === searched) {
            return;
        }

        const timer = setTimeout(
            () =>
                onChange({ searches: { ...searches, [card]: search } }, [
                    card,
                    'searches',
                    'rows',
                ]),
            300,
        );

        return () => clearTimeout(timer);
    }, [search, searched, searches, card, onChange]);

    return (
        <Card className="overflow-x-auto" aria-labelledby={id}>
            <CardHeader
                id={id}
                title={t(`connectors.umami.cards_titles.${card}`)}
                description={
                    onLabel && current?.startsWith('utm')
                        ? t('connectors.umami.utm_hint')
                        : undefined
                }
                aside={
                    // Always a search, so every card's header is as tall as the next.
                    <span className="flex flex-wrap items-center gap-2">
                        <label className="sr-only" htmlFor={`${id}-search`}>
                            {t('connectors.umami.search')}
                        </label>
                        <Input
                            id={`${id}-search`}
                            type="search"
                            placeholder={t('connectors.umami.search')}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-48 max-w-full"
                        />
                        {tabs.length > 1 && (
                            <Segmented<string>
                                label={t(
                                    `connectors.umami.cards_titles.${card}`,
                                )}
                                value={current}
                                onChange={setTab}
                                options={tabs.map((key) => ({
                                    value: key,
                                    label: t(
                                        `connectors.umami.dimensions.${key}`,
                                    ),
                                }))}
                            />
                        )}
                    </span>
                }
            />
            <Deferred
                data={card}
                fallback={
                    <div className="flex flex-col gap-2 p-4">
                        {[0, 1, 2, 3].map((i) => (
                            <Skeleton key={i} className="h-5 w-full" />
                        ))}
                    </div>
                }
            >
                {rows && current !== undefined ? (
                    <>
                        <Table
                            dimension={current}
                            rows={rows[current] ?? []}
                            total={total}
                            filter={dimensions[current] ?? null}
                            onFilter={onFilter}
                            onLabel={
                                current.startsWith('utm') ? onLabel : undefined
                            }
                        />
                        {/* A full page means Umami may have more. */}
                        {(rows[current]?.length ?? 0) >= limit &&
                            limit < MAX_ROWS && (
                                <div className="border-t border-line p-2 text-center">
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            onChange(
                                                {
                                                    rows: {
                                                        ...shown,
                                                        [card]: limit + ROWS,
                                                    },
                                                },
                                                [card, 'searches', 'rows'],
                                            )
                                        }
                                    >
                                        {t('connectors.umami.load_more')}
                                    </Button>
                                </div>
                            )}
                    </>
                ) : (
                    <EmptyState title={t('connectors.umami.empty')} />
                )}
            </Deferred>
        </Card>
    );
}

function Table({
    dimension,
    rows,
    total,
    filter,
    onFilter,
    onLabel,
}: {
    dimension: string;
    rows: Row[];
    total: number;
    filter: string | null;
    onFilter: (values: Record<string, string>) => void;
    onLabel?: SaveLabel;
}) {
    const t = useT();
    const isEvent = dimension === 'event';
    const isPage = ['path', 'entry', 'exit'].includes(dimension);
    // Umami leaves bounces and time at 0 for most breakdowns; a column of zeros would mislead.
    const measured = rows.some(
        (r) => (r.bounce_rate ?? 0) > 0 || (r.visit_duration ?? 0) > 0,
    );
    const top = Math.max(1, ...rows.map((r) => r.visitors));

    if (rows.length === 0) {
        return <EmptyState title={t('connectors.umami.empty')} />;
    }

    return (
        <table className={tableClass}>
            <thead>
                <tr>
                    <th className={thClass}>
                        {t(`connectors.umami.dimensions.${dimension}`)}
                    </th>
                    <th className={`${thClass} text-right`}>
                        {t('connectors.umami.columns.visitors')}
                    </th>
                    {isEvent ? (
                        <>
                            <th
                                className={`${thClass} text-right`}
                                title={t(
                                    'connectors.umami.columns.conversion_help',
                                )}
                            >
                                {t('connectors.umami.columns.conversion')}
                            </th>
                            <th className={`${thClass} text-right`}>
                                {t('connectors.umami.columns.count')}
                            </th>
                        </>
                    ) : (
                        measured && (
                            <>
                                <th className={`${thClass} text-right`}>
                                    {t('connectors.umami.columns.bounce_rate')}
                                </th>
                                <th className={`${thClass} text-right`}>
                                    {t(
                                        'connectors.umami.columns.visit_duration',
                                    )}
                                </th>
                            </>
                        )
                    )}
                    {isPage && (
                        <th
                            className={`${thClass} text-right`}
                            title={t('connectors.umami.columns.lcp_help')}
                        >
                            {t('connectors.umami.columns.lcp')}
                        </th>
                    )}
                </tr>
            </thead>
            <tbody>
                {rows.map((row) => (
                    <tr
                        key={`${row.host ?? ''}${row.name}`}
                        className="lj-row hover:bg-panel-2/60"
                    >
                        <td className={`${tdClass} relative min-w-[200px]`}>
                            {/* The share of the top row, drawn behind the name. */}
                            <span
                                aria-hidden="true"
                                className="absolute inset-y-1 left-0 rounded-r bg-info/10"
                                style={{
                                    width: `${(row.visitors / top) * 100}%`,
                                }}
                            />
                            {filter !== null && row.name !== '' ? (
                                <button
                                    type="button"
                                    className="relative max-w-full truncate text-left font-mono text-xs hover:text-amber hover:underline"
                                    onClick={() =>
                                        onFilter({
                                            [filter]: row.name,
                                            // A page is filtered on its own domain too.
                                            ...(row.host
                                                ? { hostname: row.host }
                                                : {}),
                                        })
                                    }
                                    title={t('connectors.umami.filters.add', {
                                        value: (row.host ?? '') + row.name,
                                    })}
                                >
                                    {row.host && (
                                        <span className="text-ink-3">
                                            {row.host}
                                        </span>
                                    )}
                                    {row.label ?? row.name}
                                </button>
                            ) : (
                                <span className="relative font-mono text-xs">
                                    {row.name === ''
                                        ? t('connectors.umami.none')
                                        : row.name}
                                </span>
                            )}
                            {row.label && (
                                <span className="relative ml-2 font-mono text-[11px] text-ink-3">
                                    {row.name}
                                </span>
                            )}
                            {onLabel && row.name !== '' && (
                                <LabelEditor
                                    value={row.name}
                                    label={row.label}
                                    field={t('connectors.umami.labels.field', {
                                        value: row.name,
                                    })}
                                    onSave={(label) =>
                                        onLabel(dimension, row.name, label)
                                    }
                                />
                            )}
                        </td>
                        <td className={`${tdClass} ${numClass}`}>
                            {formatNumber(row.visitors)}
                        </td>
                        {isEvent ? (
                            <>
                                <td className={`${tdClass} ${numClass}`}>
                                    {total > 0
                                        ? formatPercent(row.visitors / total, 0)
                                        : '-'}
                                </td>
                                <td
                                    className={`${tdClass} ${numClass} text-ink-2`}
                                >
                                    {formatNumber(row.pageviews)}
                                </td>
                            </>
                        ) : (
                            measured && (
                                <>
                                    <td className={`${tdClass} ${numClass}`}>
                                        {percent(row.bounce_rate)}
                                    </td>
                                    <td
                                        className={`${tdClass} ${numClass} text-ink-2`}
                                    >
                                        {seconds(row.visit_duration)}
                                    </td>
                                </>
                            )
                        )}
                        {isPage && (
                            <td
                                className={cn(
                                    tdClass,
                                    numClass,
                                    row.lcp_us == null
                                        ? 'text-ink-3'
                                        : row.lcp_us <= LCP[0]
                                          ? 'text-ok'
                                          : row.lcp_us <= LCP[1]
                                            ? 'text-warn'
                                            : 'text-err',
                                )}
                            >
                                {formatDuration(row.lcp_us)}
                            </td>
                        )}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/** Short weekday names in the app's language, Monday first (1 January 2024 was one). */
const weekdays = () =>
    [0, 1, 2, 3, 4, 5, 6].map((i) =>
        new Intl.DateTimeFormat(locale(), { weekday: 'short' }).format(
            new Date(2024, 0, 1 + i),
        ),
    );

/** Visitors by hour of the week: when the site is busy, Monday first. */
function Heatmap({ grid }: { grid: number[][] | undefined }) {
    const t = useT();
    const WEEKDAYS = weekdays();
    const max = Math.max(1, ...(grid ?? []).flat());
    const busiest = (grid ?? [])
        .flatMap((hours, day) => hours.map((v, hour) => ({ v, day, hour })))
        .sort((a, b) => b.v - a.v)[0];

    return (
        <Card aria-labelledby="umami-heatmap">
            <CardHeader
                id="umami-heatmap"
                title={t('connectors.umami.heatmap.title')}
                description={t('connectors.umami.heatmap.description')}
            />
            <Deferred
                data="heatmap"
                fallback={<Skeleton className="m-4 h-40" />}
            >
                <div className="overflow-x-auto p-4">
                    <div
                        role="img"
                        aria-label={
                            busiest && busiest.v > 0
                                ? t('connectors.umami.heatmap.summary', {
                                      day: WEEKDAYS[busiest.day],
                                      hour: String(busiest.hour).padStart(
                                          2,
                                          '0',
                                      ),
                                      count: formatNumber(busiest.v),
                                  })
                                : t('connectors.umami.empty')
                        }
                        className="grid min-w-[560px] grid-cols-[2.5rem_repeat(24,minmax(0,1fr))] gap-[3px]"
                    >
                        {(grid ?? []).map((hours, day) => (
                            <Fragment key={day}>
                                <span
                                    aria-hidden="true"
                                    className="self-center text-[11px] text-ink-3"
                                >
                                    {WEEKDAYS[day]}
                                </span>
                                {hours.map((v, hour) => (
                                    <span
                                        key={hour}
                                        className="h-5 rounded-sm bg-panel-2"
                                        title={t(
                                            'connectors.umami.heatmap.cell',
                                            {
                                                day: WEEKDAYS[day],
                                                hour: String(hour).padStart(
                                                    2,
                                                    '0',
                                                ),
                                                count: formatNumber(v),
                                            },
                                        )}
                                        style={
                                            v > 0
                                                ? {
                                                      backgroundColor:
                                                          'var(--lj-info)',
                                                      opacity:
                                                          0.15 +
                                                          (v / max) * 0.85,
                                                  }
                                                : undefined
                                        }
                                    />
                                ))}
                            </Fragment>
                        ))}
                        <span aria-hidden="true" />
                        {Array.from({ length: 24 }, (_, hour) => (
                            <span
                                key={hour}
                                aria-hidden="true"
                                className="text-center font-mono text-[10px] text-ink-3"
                            >
                                {hour % 3 === 0
                                    ? String(hour).padStart(2, '0')
                                    : ''}
                            </span>
                        ))}
                    </div>
                </div>
            </Deferred>
        </Card>
    );
}

function Connection({
    project,
    connection,
}: {
    project: string;
    connection: NonNullable<Props['connection']>;
}) {
    const t = useT();

    return (
        <ConnectionCard
            project={project}
            connection={connection.uuid}
            description={
                connection.hosting === 'cloud'
                    ? t('connectors.umami.settings.cloud', {
                          timezone: connection.timezone,
                      })
                    : t('connectors.umami.settings.self_hosted', {
                          instance: connection.instance ?? '',
                          timezone: connection.timezone,
                      })
            }
            connectLabel={t('connectors.umami.settings.add')}
            disconnectQuestion={t(
                'connectors.umami.settings.disconnect_question',
            )}
            dialog={(onClose) => (
                <UmamiConnectDialog project={project} onClose={onClose} />
            )}
        >
            <UmamiWebsiteSwitcher
                key={`change-${connection.uuid}`}
                project={project}
                connection={connection.uuid}
                current={connection.website_id}
                mode="change"
            />
            <div className="flex flex-col gap-1.5 border-t border-line pt-4">
                <p className="text-xs text-ink-2">
                    {t('connectors.umami.settings.add_hint')}
                </p>
                <UmamiWebsiteSwitcher
                    key={`add-${connection.uuid}`}
                    project={project}
                    connection={connection.uuid}
                    current={null}
                    mode="add"
                />
            </div>
        </ConnectionCard>
    );
}
