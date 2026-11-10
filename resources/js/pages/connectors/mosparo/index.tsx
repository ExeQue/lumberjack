import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    ConnectionCard,
    ConnectorPanel,
    ConnectorTabs,
    useHashTab,
    useTrend,
} from '@/components/lj/connectors/connection';
import { MosparoConnectDialog } from '@/components/lj/connectors/mosparo/connect';
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
import { formatNumber, formatPercent, formatRelative } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { index as mosparoIndex } from '@/routes/connectors/mosparo';
import { show as projectShow } from '@/routes/projects';
import type { Period, ProjectHeader, TeamShared } from '@/types/lumberjack';

type Point = { t: string; valid: number; spam: number };

type Figures = {
    valid: number;
    spam: number;
    spam_rate: number | null;
    delayed: number;
    blocked: number;
};

type Props = {
    project: ProjectHeader;
    filters: { period: Period };
    connection: {
        uuid: string;
        name: string;
        instance: string;
        timezone: string;
    } | null;
    /** Every mosparo project the project reads, by the uuid of its connection. */
    projects: { uuid: string; name: string }[];
    error: string | null;
    summary: {
        current: Figures;
        previous: Figures;
        last_submission_at: string | null;
    } | null;
    /** Each day of the period, today included, oldest first. */
    series: Point[];
};

/**
 * mosparo counts whole days, so it has no hour, and 24 hours is today. A month counts only
 * what mosparo still keeps, by default 14 days.
 */
const PERIODS: Period[] = ['24h', '7d', '30d'];

const TABS = ['overview', 'connection'] as const;

type Tab = (typeof TABS)[number];

export default function MosparoIndex({
    project,
    filters,
    connection,
    projects,
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
                ? mosparoIndex([project.uuid, chosen], { query }).url
                : mosparoIndex({ project: project.uuid }, { query }).url,
            {},
            { preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head
                title={`${t('connectors.mosparo.title')} · ${project.name}`}
            />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('connectors.mosparo.title') },
                ]}
            >
                {connection && (
                    <Segmented<Period>
                        label={t('common.filters.period')}
                        value={filters.period}
                        onChange={(period) => visit({ period })}
                        options={PERIODS.map((period) => ({
                            value: period,
                            label: t(`connectors.mosparo.periods.${period}`),
                        }))}
                    />
                )}
            </TopBar>
            <PageBody>
                <PageTitle
                    title={t('connectors.mosparo.title')}
                    description={
                        connection
                            ? t('connectors.mosparo.description', {
                                  name: connection.name,
                              })
                            : t('connectors.mosparo.not_connected.description')
                    }
                    actions={
                        connection &&
                        projects.length > 1 && (
                            <Select
                                aria-label={t('connectors.mosparo.project')}
                                value={connection.uuid}
                                className="w-auto"
                                onChange={(e) =>
                                    visit({ connection: e.target.value })
                                }
                            >
                                {projects.map((p) => (
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
                                id="mosparo"
                                label={t('connectors.mosparo.tabs.label')}
                                tabs={TABS}
                                current={tab}
                                onChange={setTab}
                                title={(name) =>
                                    t(`connectors.mosparo.tabs.${name}`)
                                }
                            />
                        )}
                        <ConnectorPanel
                            id="mosparo"
                            name="overview"
                            current={tab}
                        >
                            {error !== null ? (
                                <Card>
                                    <EmptyState
                                        title={t(
                                            'connectors.mosparo.error_title',
                                        )}
                                    >
                                        <p>{error}</p>
                                    </EmptyState>
                                </Card>
                            ) : (
                                summary && (
                                    <Submissions
                                        series={series}
                                        period={filters.period}
                                    />
                                )
                            )}
                        </ConnectorPanel>
                        {project.can_administer && (
                            <ConnectorPanel
                                id="mosparo"
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

function Summary({ summary }: { summary: NonNullable<Props['summary']> }) {
    const t = useT();
    const trend = useTrend();
    const { current, previous } = summary;

    return (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,160px),1fr))] gap-4">
            <StatCard
                label={t('connectors.mosparo.cards.valid')}
                value={formatNumber(current.valid)}
                tone="ok"
                note={trend(current.valid, previous.valid)}
            />
            <StatCard
                label={t('connectors.mosparo.cards.spam')}
                value={formatNumber(current.spam)}
                tone={current.spam > 0 ? 'err' : undefined}
                note={trend(current.spam, previous.spam)}
            />
            <StatCard
                label={t('connectors.mosparo.cards.spam_rate')}
                value={
                    current.spam_rate === null
                        ? '-'
                        : formatPercent(current.spam_rate / 100, 1)
                }
                note={trend(current.spam_rate, previous.spam_rate)}
            />
            <StatCard
                label={t('connectors.mosparo.cards.blocked')}
                value={formatNumber(current.blocked)}
                tone={current.blocked > 0 ? 'warn' : undefined}
                note={trend(current.blocked, previous.blocked)}
            />
            <StatCard
                label={t('connectors.mosparo.cards.delayed')}
                value={formatNumber(current.delayed)}
                note={trend(current.delayed, previous.delayed)}
            />
            <StatCard
                label={t('connectors.mosparo.cards.last_submission')}
                value={
                    <span className="text-base">
                        {summary.last_submission_at
                            ? formatRelative(summary.last_submission_at)
                            : t('connectors.mosparo.never')}
                    </span>
                }
            />
        </div>
    );
}

/** Valid or spam submissions over the period. */
function Submissions({ series, period }: { series: Point[]; period: Period }) {
    const t = useT();
    const [metric, setMetric] = useState<'valid' | 'spam'>('valid');
    const total = (key: 'valid' | 'spam') =>
        series.reduce((sum, point) => sum + point[key], 0);

    return (
        <Card aria-labelledby="mosparo-series">
            <CardHeader
                id="mosparo-series"
                title={t('connectors.mosparo.series.title')}
                description={t('connectors.mosparo.series.description')}
                aside={
                    <Segmented<'valid' | 'spam'>
                        label={t('connectors.mosparo.series.title')}
                        value={metric}
                        onChange={setMetric}
                        options={[
                            {
                                value: 'valid',
                                label: t('connectors.mosparo.cards.valid'),
                            },
                            {
                                value: 'spam',
                                label: t('connectors.mosparo.cards.spam'),
                            },
                        ]}
                    />
                }
            />
            <div className="p-4">
                <TimeBars
                    series={series.map((point) => ({
                        t: point.t,
                        v: point[metric],
                    }))}
                    period={period}
                    color={metric === 'spam' ? 'var(--lj-err)' : 'var(--lj-ok)'}
                    height={140}
                    formatValue={(v) => formatNumber(v)}
                    label={t('connectors.mosparo.series.summary', {
                        valid: formatNumber(total('valid')),
                        spam: formatNumber(total('spam')),
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
            <EmptyState title={t('connectors.mosparo.not_connected.title')}>
                <p>
                    {project.can_administer
                        ? t('connectors.mosparo.not_connected.body')
                        : t('connectors.mosparo.not_connected.members')}
                </p>
                {project.can_administer && (
                    <Button
                        variant="primary"
                        className="mt-3"
                        onClick={() => setConnecting(true)}
                    >
                        {t('connectors.mosparo.not_connected.title')}
                    </Button>
                )}
            </EmptyState>
            {connecting && (
                <MosparoConnectDialog
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
            description={t('connectors.mosparo.settings.body', {
                instance: connection.instance,
                timezone: connection.timezone,
            })}
            connectLabel={t('connectors.mosparo.settings.add')}
            disconnectQuestion={t(
                'connectors.mosparo.settings.disconnect_question',
            )}
            dialog={(onClose) => (
                <MosparoConnectDialog project={project} onClose={onClose} />
            )}
        />
    );
}
