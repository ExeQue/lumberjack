import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
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
    Button,
    Card,
    CardHeader,
    EmptyState,
    PageTitle,
    Segmented,
    Select,
    StatCard,
    TimeBars,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatNumber, locale } from '@/lib/format';
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
};

type Props = {
    project: ProjectHeader;
    filters: { period: Period };
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
        current: Figures;
        previous: Figures;
    } | null;
    /** Each day of the period, today included, oldest first. */
    series: Point[];
};

/** The ledger is booked per day, so it has no hour, and 24 hours is today. */
const PERIODS: Period[] = ['24h', '7d', '30d'];

const TABS = ['overview', 'connection'] as const;

type Tab = (typeof TABS)[number];

export default function MobilePayIndex({
    project,
    filters,
    connection,
    ledgers,
    error,
    summary,
    series,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [tab, setTab] = useHashTab<Tab>(TABS);

    const visit = (changes: { period?: Period; connection?: string }) => {
        const period = changes.period ?? filters.period;
        const query = period === '7d' ? {} : { period };
        const chosen = changes.connection ?? connection?.uuid;

        router.get(
            chosen
                ? mobilepayIndex([project.uuid, chosen], { query }).url
                : mobilepayIndex({ project: project.uuid }, { query }).url,
            {},
            { preserveScroll: true, replace: true },
        );
    };

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
                                    <Revenue
                                        series={series}
                                        period={filters.period}
                                        currency={summary.currency}
                                    />
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
                label={t('connectors.mobilepay.cards.refunds')}
                value={money(current.refunds)}
                tone={current.refunds > 0 ? 'warn' : undefined}
                note={trend(current.refunds, previous.refunds)}
            />
            <StatCard
                label={t('connectors.mobilepay.cards.fees')}
                value={money(current.fees)}
                note={trend(current.fees, previous.fees)}
            />
            <StatCard
                label={t('connectors.mobilepay.cards.payouts')}
                value={money(current.payouts)}
                note={trend(current.payouts, previous.payouts)}
            />
        </div>
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
