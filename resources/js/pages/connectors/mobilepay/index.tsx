import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    ConnectionCard,
    ConnectorPanel,
    ConnectorTabs,
    useHashTab,
    useTrend,
} from '@/components/lj/connectors/connection';
import { MobilePayConnectDialog } from '@/components/lj/connectors/mobilepay/connect';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Bars,
    Button,
    Card,
    CardHeader,
    EmptyState,
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
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatDateTime,
    formatNumber,
    formatPercent,
    locale,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { index as mobilepayIndex } from '@/routes/connectors/mobilepay';
import { show as projectShow } from '@/routes/projects';
import type { Period, ProjectHeader, TeamShared } from '@/types/lumberjack';

type Point = { t: string; revenue: number; transactions: number };

/** Amounts in whole units of the ledger's currency. */
type Figures = {
    revenue: number;
    transactions: number;
    refunds: number;
    fees: number;
    payouts: number;
    /** Revenue per sale, null without sales. */
    average_order: number | null;
    /** Refunds and fees as a percentage of the revenue, null without revenue. */
    refund_rate: number | null;
    fee_rate: number | null;
};

type Sales = { sales: number; revenue: number };

/** The period's lists and breakdowns, amounts in whole units. */
type Details = {
    latest: {
        at: string;
        type: 'capture' | 'refund';
        amount: number;
        reference: string;
    }[];
    largest: { at: string; amount: number; reference: string }[];
    payouts: { date: string; amount: number }[];
    /** Every hour of the day, 0 to 23, in the connection's time zone. */
    hours: (Sales & { hour: number })[];
    /** Every weekday, Monday first. */
    weekdays: (Sales & { label: string })[];
    units: { unit: string; revenue: number }[];
};

type Props = {
    project: ProjectHeader;
    filters: { period: Period };
    /** How many rows each list shows, when more than at first. */
    rows: Partial<Record<List, number>>;
    connection: {
        uuid: string;
        name: string;
        timezone: string;
    } | null;
    /** Every ledger the project reads, by the uuid of its connection. */
    ledgers: { uuid: string; name: string }[];
    error: string | null;
    summary: {
        currency: string;
        /** What Vipps MobilePay holds for the merchant now, after the latest entry. */
        balance: number | null;
        current: Figures;
        previous: Figures;
    } | null;
    /** Each day of the period, today included, oldest first. */
    series: Point[];
    details: Details | null;
};

/**
 * Each list's rows at first, which is also how many each Load more adds, and the most it
 * loads, as the controller holds them.
 */
const LISTS = {
    transactions: { rows: 20, max: 200 },
    largest: { rows: 10, max: 100 },
    payouts: { rows: 20, max: 200 },
} as const;

type List = keyof typeof LISTS;

/** Shows the next rows of a list, partially reloading only the lists. */
type LoadMore = (list: List) => void;

/** The ledger is booked per day, so it has no hour, and 24 hours is today. */
const PERIODS: Period[] = ['24h', '7d', '30d'];

const TABS = ['overview', 'connection'] as const;

type Tab = (typeof TABS)[number];

export default function MobilePayIndex({
    project,
    filters,
    rows,
    connection,
    ledgers,
    error,
    summary,
    series,
    details,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [tab, setTab] = useHashTab<Tab>(TABS);

    /**
     * Visits the page as changed. The rows loaded are kept in the query until the ledger or
     * period changes.
     */
    const visit = (
        changes: {
            period?: Period;
            connection?: string;
            rows?: Props['rows'];
        },
        only?: string[],
    ) => {
        const period = changes.period ?? filters.period;
        const query: Record<string, string> = period === '7d' ? {} : { period };
        const resets =
            changes.connection !== undefined || changes.period !== undefined;
        const chosen = changes.connection ?? connection?.uuid;

        Object.entries(changes.rows ?? (resets ? {} : rows)).forEach(
            ([list, count]) => (query[`rows[${list}]`] = String(count)),
        );

        router.get(
            (chosen
                ? mobilepayIndex([project.uuid, chosen], { query }).url
                : mobilepayIndex({ project: project.uuid }, { query }).url) +
                window.location.hash,
            {},
            {
                preserveScroll: true,
                replace: true,
                ...(only ? { only, preserveState: true } : {}),
            },
        );
    };

    const loadMore: LoadMore = (list) =>
        visit(
            {
                rows: {
                    ...rows,
                    [list]: (rows[list] ?? LISTS[list].rows) + LISTS[list].rows,
                },
            },
            ['details', 'rows'],
        );

    return (
        <>
            <Head
                title={`${t('connectors.mobilepay.title')} · ${project.name}`}
            />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('connectors.mobilepay.title') },
                ]}
            >
                {connection && (
                    <Segmented<Period>
                        label={t('common.filters.period')}
                        value={filters.period}
                        onChange={(period) => visit({ period })}
                        options={PERIODS.map((period) => ({
                            value: period,
                            label: t(`connectors.mobilepay.periods.${period}`),
                        }))}
                    />
                )}
            </TopBar>
            <PageBody>
                <PageTitle
                    title={t('connectors.mobilepay.title')}
                    description={
                        connection
                            ? t('connectors.mobilepay.description', {
                                  name: connection.name,
                              })
                            : t(
                                  'connectors.mobilepay.not_connected.description',
                              )
                    }
                    actions={
                        connection &&
                        ledgers.length > 1 && (
                            <Select
                                aria-label={t('connectors.mobilepay.ledger')}
                                value={connection.uuid}
                                className="w-auto"
                                onChange={(e) =>
                                    visit({ connection: e.target.value })
                                }
                            >
                                {ledgers.map((p) => (
                                    <option key={p.uuid} value={p.uuid}>
                                        {p.name}
                                    </option>
                                ))}
                            </Select>
                        )
                    }
                />

                {connection === null ? (
                    <NotConnected project={project} />
                ) : (
                    <>
                        {summary && error === null && (
                            <Summary summary={summary} />
                        )}
                        {project.can_administer && (
                            <ConnectorTabs<Tab>
                                id="mobilepay"
                                label={t('connectors.mobilepay.tabs.label')}
                                tabs={TABS}
                                current={tab}
                                onChange={setTab}
                                title={(name) =>
                                    t(`connectors.mobilepay.tabs.${name}`)
                                }
                            />
                        )}
                        <ConnectorPanel
                            id="mobilepay"
                            name="overview"
                            current={tab}
                        >
                            {error !== null ? (
                                <Card>
                                    <EmptyState
                                        title={t(
                                            'connectors.mobilepay.error_title',
                                        )}
                                    >
                                        <p>{error}</p>
                                    </EmptyState>
                                </Card>
                            ) : (
                                summary && (
                                    <div className="flex flex-col gap-4">
                                        <Revenue
                                            series={series}
                                            period={filters.period}
                                            currency={summary.currency}
                                        />
                                        {details && (
                                            <Overview
                                                details={details}
                                                currency={summary.currency}
                                                timezone={connection.timezone}
                                                shown={rows}
                                                onMore={loadMore}
                                            />
                                        )}
                                    </div>
                                )
                            )}
                        </ConnectorPanel>
                        {project.can_administer && (
                            <ConnectorPanel
                                id="mobilepay"
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

/** An amount in the ledger's currency, as the locale writes it. */
function formatMoney(value: number, currency: string): string {
    try {
        return new Intl.NumberFormat(locale(), {
            style: 'currency',
            currency,
        }).format(value);
    } catch {
        // A currency the browser does not know.
        return `${formatNumber(value, 2)} ${currency}`;
    }
}

function Summary({ summary }: { summary: NonNullable<Props['summary']> }) {
    const t = useT();
    const trend = useTrend();
    const { current, previous, currency } = summary;
    const money = (value: number) => formatMoney(value, currency);

    return (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,160px),1fr))] gap-4">
            <StatCard
                label={t('connectors.mobilepay.cards.revenue')}
                value={money(current.revenue)}
                tone="ok"
                note={trend(current.revenue, previous.revenue)}
            />
            <StatCard
                label={t('connectors.mobilepay.cards.transactions')}
                value={formatNumber(current.transactions)}
                note={trend(current.transactions, previous.transactions)}
            />
            <StatCard
                label={t('connectors.mobilepay.cards.average_order')}
                value={
                    current.average_order === null
                        ? '-'
                        : money(current.average_order)
                }
                note={trend(current.average_order, previous.average_order)}
            />
            <StatCard
                label={t('connectors.mobilepay.cards.refunds')}
                value={money(current.refunds)}
                tone={current.refunds > 0 ? 'warn' : undefined}
                note={trend(current.refunds, previous.refunds)}
            />
            <StatCard
                label={t('connectors.mobilepay.cards.refund_rate')}
                value={percent(current.refund_rate)}
                note={trend(current.refund_rate, previous.refund_rate)}
            />
            <StatCard
                label={t('connectors.mobilepay.cards.fees')}
                value={money(current.fees)}
                note={trend(current.fees, previous.fees)}
            />
            <StatCard
                label={t('connectors.mobilepay.cards.fee_rate')}
                value={percent(current.fee_rate)}
                note={trend(current.fee_rate, previous.fee_rate)}
            />
            <StatCard
                label={t('connectors.mobilepay.cards.payouts')}
                value={money(current.payouts)}
                note={trend(current.payouts, previous.payouts)}
            />
            <StatCard
                label={t('connectors.mobilepay.cards.balance')}
                value={summary.balance === null ? '-' : money(summary.balance)}
                note={t('connectors.mobilepay.cards.balance_note')}
            />
        </div>
    );
}

/** A percentage of the revenue, as the locale writes it. */
function percent(value: number | null): string {
    return value === null ? '-' : formatPercent(value / 100, 1);
}

/** The period's breakdowns and lists, below the revenue. */
function Overview({
    details,
    currency,
    timezone,
    shown,
    onMore,
}: {
    details: Details;
    currency: string;
    timezone: string;
    shown: Props['rows'];
    onMore: LoadMore;
}) {
    const t = useT();
    const money = (value: number) => formatMoney(value, currency);
    const hours = details.hours.map((hour) => ({
        ...hour,
        label: `${String(hour.hour).padStart(2, '0')}:00`,
    }));

    return (
        <>
            <div className="grid gap-4 lg:grid-cols-2">
                <Breakdown
                    id="mobilepay-hours"
                    title={t('connectors.mobilepay.hours.title')}
                    description={t('connectors.mobilepay.hours.description', {
                        timezone,
                    })}
                    slots={hours}
                    summary={(busiest) =>
                        t(
                            'connectors.mobilepay.hours.summary',
                            { hour: busiest.label },
                            busiest.sales,
                        )
                    }
                    money={money}
                />
                <Breakdown
                    id="mobilepay-weekdays"
                    title={t('connectors.mobilepay.weekdays.title')}
                    description={t(
                        'connectors.mobilepay.weekdays.description',
                        { timezone },
                    )}
                    slots={details.weekdays}
                    summary={(busiest) =>
                        t(
                            'connectors.mobilepay.weekdays.summary',
                            { weekday: busiest.label },
                            busiest.sales,
                        )
                    }
                    money={money}
                />
            </div>
            <ListCard
                id="mobilepay-transactions"
                title={t('connectors.mobilepay.transactions.title')}
                description={t('connectors.mobilepay.transactions.description')}
                empty={t('connectors.mobilepay.transactions.empty')}
                head={[
                    t('connectors.mobilepay.time'),
                    t('connectors.mobilepay.transactions.type'),
                    t('connectors.mobilepay.reference'),
                ]}
                rows={details.latest.map((entry) => [
                    formatDateTime(entry.at),
                    <Badge
                        key="type"
                        tone={entry.type === 'refund' ? 'warn' : 'ok'}
                    >
                        {t(`connectors.mobilepay.transactions.${entry.type}`)}
                    </Badge>,
                    entry.reference,
                    money(entry.amount),
                ])}
                more={{ list: 'transactions', shown, onMore }}
            />
            <div className="grid gap-4 lg:grid-cols-2">
                <ListCard
                    id="mobilepay-largest"
                    title={t('connectors.mobilepay.largest.title')}
                    description={t('connectors.mobilepay.largest.description')}
                    empty={t('connectors.mobilepay.largest.empty')}
                    head={[
                        t('connectors.mobilepay.time'),
                        t('connectors.mobilepay.reference'),
                    ]}
                    rows={details.largest.map((sale) => [
                        formatDateTime(sale.at),
                        sale.reference,
                        money(sale.amount),
                    ])}
                    more={{ list: 'largest', shown, onMore }}
                />
                <ListCard
                    id="mobilepay-payouts"
                    title={t('connectors.mobilepay.payouts.title')}
                    description={t('connectors.mobilepay.payouts.description')}
                    empty={t('connectors.mobilepay.payouts.empty')}
                    head={[t('connectors.mobilepay.payouts.date')]}
                    rows={details.payouts.map((payout) => [
                        payout.date,
                        money(payout.amount),
                    ])}
                    more={{ list: 'payouts', shown, onMore }}
                />
            </div>
            <ListCard
                id="mobilepay-units"
                title={t('connectors.mobilepay.units.title')}
                description={t('connectors.mobilepay.units.description')}
                empty={t('connectors.mobilepay.units.empty')}
                head={[t('connectors.mobilepay.units.unit')]}
                amountHead={t('connectors.mobilepay.units.revenue')}
                rows={details.units.map((unit) => [
                    <span key="unit" className="font-mono">
                        {unit.unit}
                    </span>,
                    money(unit.revenue),
                ])}
            />
        </>
    );
}

/** Sales per hour or weekday as bars, the sales and revenue of each on hover. */
function Breakdown<S extends Sales & { label: string }>({
    id,
    title,
    description,
    slots,
    summary,
    money,
}: {
    id: string;
    title: string;
    description: string;
    slots: S[];
    summary: (busiest: S) => string;
    money: (value: number) => string;
}) {
    const t = useT();
    const busiest = slots.reduce((top, slot) =>
        slot.sales > top.sales ? slot : top,
    );

    return (
        <Card aria-labelledby={id}>
            <CardHeader id={id} title={title} description={description} />
            <div className="p-4">
                <Bars
                    values={slots.map((slot) => slot.sales)}
                    color="var(--lj-ok)"
                    height={120}
                    label={summary(busiest)}
                    tips={slots.map((slot) =>
                        t(
                            'connectors.mobilepay.hours.tip',
                            { label: slot.label, revenue: money(slot.revenue) },
                            slot.sales,
                        ),
                    )}
                />
                <div className="mt-1 flex justify-between font-mono text-[11px] text-ink-3">
                    <span>{slots[0]?.label}</span>
                    <span>{slots[slots.length - 1]?.label}</span>
                </div>
            </div>
        </Card>
    );
}

/** A table whose last column is an amount, with Load more below when it is a list that grows. */
function ListCard({
    id,
    title,
    description,
    empty,
    head,
    amountHead,
    rows,
    more,
}: {
    id: string;
    title: string;
    description: string;
    empty: string;
    head: string[];
    amountHead?: string;
    rows: ReactNode[][];
    more?: { list: List; shown: Props['rows']; onMore: LoadMore };
}) {
    const t = useT();
    const limit = more ? (more.shown[more.list] ?? LISTS[more.list].rows) : 0;

    return (
        <Card aria-labelledby={id}>
            <CardHeader id={id} title={title} description={description} />
            {rows.length === 0 ? (
                <EmptyState title={empty} />
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                {head.map((label) => (
                                    <th key={label} className={thClass}>
                                        {label}
                                    </th>
                                ))}
                                <th className={`${thClass} text-right`}>
                                    {amountHead ??
                                        t('connectors.mobilepay.amount')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((cells, i) => (
                                <tr key={i}>
                                    {cells.map((cell, j) => (
                                        <td
                                            key={j}
                                            className={
                                                j === cells.length - 1
                                                    ? `${tdClass} ${numClass}`
                                                    : j === 0
                                                      ? `${tdClass} whitespace-nowrap`
                                                      : `${tdClass} break-all`
                                            }
                                        >
                                            {cell}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {more && rows.length >= limit && limit < LISTS[more.list].max && (
                <div className="border-t border-line p-2 text-center">
                    <Button
                        variant="ghost"
                        onClick={() => more.onMore(more.list)}
                    >
                        {t('connectors.mobilepay.load_more')}
                    </Button>
                </div>
            )}
        </Card>
    );
}

/** Revenue per ledger date over the period. */
function Revenue({
    series,
    period,
    currency,
}: {
    series: Point[];
    period: Period;
    currency: string;
}) {
    const t = useT();
    const total = (key: 'revenue' | 'transactions') =>
        series.reduce((sum, point) => sum + point[key], 0);

    return (
        <Card aria-labelledby="mobilepay-series">
            <CardHeader
                id="mobilepay-series"
                title={t('connectors.mobilepay.series.title')}
                description={t('connectors.mobilepay.series.description')}
            />
            <div className="p-4">
                <TimeBars
                    series={series.map((point) => ({
                        t: point.t,
                        v: point.revenue,
                    }))}
                    period={period}
                    color="var(--lj-ok)"
                    height={140}
                    formatValue={(v) => formatMoney(v, currency)}
                    label={t('connectors.mobilepay.series.summary', {
                        revenue: formatMoney(total('revenue'), currency),
                        transactions: formatNumber(total('transactions')),
                    })}
                />
            </div>
        </Card>
    );
}

function NotConnected({ project }: { project: ProjectHeader }) {
    const t = useT();
    const [connecting, setConnecting] = useState(false);

    return (
        <Card>
            <EmptyState title={t('connectors.mobilepay.not_connected.title')}>
                <p>
                    {project.can_administer
                        ? t('connectors.mobilepay.not_connected.body')
                        : t('connectors.mobilepay.not_connected.members')}
                </p>
                {project.can_administer && (
                    <Button
                        variant="primary"
                        className="mt-3"
                        onClick={() => setConnecting(true)}
                    >
                        {t('connectors.mobilepay.not_connected.title')}
                    </Button>
                )}
            </EmptyState>
            {connecting && (
                <MobilePayConnectDialog
                    project={project.uuid}
                    onClose={() => setConnecting(false)}
                />
            )}
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
            description={t('connectors.mobilepay.settings.body', {
                name: connection.name,
                timezone: connection.timezone,
            })}
            connectLabel={t('connectors.mobilepay.settings.add')}
            disconnectQuestion={t(
                'connectors.mobilepay.settings.disconnect_question',
            )}
            dialog={(onClose) => (
                <MobilePayConnectDialog project={project} onClose={onClose} />
            )}
        />
    );
}
