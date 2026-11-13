import { Head, router, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import {
    ConnectionCard,
    ConnectorPanel,
    ConnectorTabs,
    useHashTab,
    useTrend,
} from '@/components/lj/connectors/connection';
import { PackagistConnectDialog } from '@/components/lj/connectors/packagist/connect';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
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
import type { Tone } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatDate, formatNumber, formatPercent } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as packagistIndex } from '@/routes/connectors/packagist';
import { show as projectShow } from '@/routes/projects';
import type { Period, ProjectHeader, TeamShared } from '@/types/lumberjack';

type Summary = {
    downloads: number;
    previous: number;
    /** The last day the downloads cover. */
    through: string | null;
    total: number;
    monthly: number;
    favers: number;
    dependents: number;
    suggesters: number;
    github_stars: number | null;
    advisories: number;
};

type Advisory = {
    id: string;
    title: string;
    link: string | null;
    cve: string | null;
    affected: string;
    severity: string | null;
    reported_at: string | null;
};

type Props = {
    project: ProjectHeader;
    filters: { period: Period };
    connection: { uuid: string; name: string } | null;
    /** Every package the project reads, by the uuid of its connection. */
    packages: { uuid: string; name: string }[];
    error: string | null;
    package: {
        name: string;
        description: string;
        repository: string | null;
        url: string;
    } | null;
    summary: Summary | null;
    /** Each day of the period Packagist has published, oldest first. */
    series: { t: string; downloads: number }[];
    majors: { major: string; downloads: number; share: number }[];
    releases: { version: string; time: string | null }[];
    advisories: Advisory[];
};

/** Packagist counts whole days, so it has no hour; 24 hours is the last day it published. */
const PERIODS: Period[] = ['24h', '7d', '30d'];

const TABS = ['overview', 'versions', 'advisories', 'connection'] as const;

type Tab = (typeof TABS)[number];

const SEVERITY_TONES: Record<string, Tone> = {
    critical: 'err',
    high: 'err',
    medium: 'warn',
    low: 'neutral',
};

const link = 'inline-flex items-center gap-1 text-amber hover:underline';

export default function PackagistIndex(props: Props) {
    const { project, filters, connection, packages, error, summary } = props;
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [tab, setTab] = useHashTab<Tab>(TABS);
    // Without an answer from Packagist there is only the error and the connection.
    const tabs = TABS.filter(
        (name) =>
            (name !== 'connection' || project.can_administer) &&
            (error === null || name === 'overview' || name === 'connection'),
    );

    const visit = (changes: { period?: Period; connection?: string }) => {
        const period = changes.period ?? filters.period;
        const query = period === '30d' ? {} : { period };
        const chosen = changes.connection ?? connection?.uuid;

        router.get(
            chosen
                ? packagistIndex([project.uuid, chosen], { query }).url
                : packagistIndex({ project: project.uuid }, { query }).url,
            {},
            { preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head
                title={`${t('connectors.packagist.title')} · ${project.name}`}
            />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('connectors.packagist.title') },
                ]}
            >
                {connection && (
                    <Segmented<Period>
                        label={t('common.filters.period')}
                        value={filters.period}
                        onChange={(period) => visit({ period })}
                        options={PERIODS.map((period) => ({
                            value: period,
                            label: t(`connectors.packagist.periods.${period}`),
                        }))}
                    />
                )}
            </TopBar>
            <PageBody>
                <PageTitle
                    title={t('connectors.packagist.title')}
                    description={
                        connection
                            ? t('connectors.packagist.description', {
                                  name: connection.name,
                              })
                            : t(
                                  'connectors.packagist.not_connected.description',
                              )
                    }
                    actions={
                        connection &&
                        packages.length > 1 && (
                            <Select
                                aria-label={t('connectors.packagist.package')}
                                value={connection.uuid}
                                className="w-auto"
                                onChange={(e) =>
                                    visit({ connection: e.target.value })
                                }
                            >
                                {packages.map((p) => (
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
                        {summary && error === null && props.package && (
                            <Figures summary={summary} pkg={props.package} />
                        )}
                        <ConnectorTabs<Tab>
                            id="packagist"
                            label={t('connectors.packagist.tabs.label')}
                            tabs={tabs}
                            current={tab}
                            onChange={setTab}
                            title={(name) =>
                                t(`connectors.packagist.tabs.${name}`)
                            }
                        />
                        {error !== null ? (
                            <ConnectorPanel
                                id="packagist"
                                name="overview"
                                current={tab}
                            >
                                <Card>
                                    <EmptyState
                                        title={t(
                                            'connectors.packagist.error_title',
                                        )}
                                    >
                                        <p>{error}</p>
                                    </EmptyState>
                                </Card>
                            </ConnectorPanel>
                        ) : (
                            <>
                                <ConnectorPanel
                                    id="packagist"
                                    name="overview"
                                    current={tab}
                                >
                                    <Downloads
                                        series={props.series}
                                        period={filters.period}
                                    />
                                </ConnectorPanel>
                                <ConnectorPanel
                                    id="packagist"
                                    name="versions"
                                    current={tab}
                                >
                                    <Majors majors={props.majors} />
                                    <Releases releases={props.releases} />
                                </ConnectorPanel>
                                <ConnectorPanel
                                    id="packagist"
                                    name="advisories"
                                    current={tab}
                                >
                                    <Advisories advisories={props.advisories} />
                                </ConnectorPanel>
                            </>
                        )}
                        {project.can_administer && (
                            <ConnectorPanel
                                id="packagist"
                                name="connection"
                                current={tab}
                            >
                                <ConnectionCard
                                    project={project.uuid}
                                    connection={connection.uuid}
                                    description={t(
                                        'connectors.packagist.settings.body',
                                        { package: connection.name },
                                    )}
                                    connectLabel={t(
                                        'connectors.packagist.settings.add',
                                    )}
                                    disconnectQuestion={t(
                                        'connectors.packagist.settings.disconnect_question',
                                    )}
                                    dialog={(onClose) => (
                                        <PackagistConnectDialog
                                            project={project.uuid}
                                            onClose={onClose}
                                        />
                                    )}
                                />
                            </ConnectorPanel>
                        )}
                    </>
                )}
            </PageBody>
        </>
    );
}

function Figures({
    summary,
    pkg,
}: {
    summary: Summary;
    pkg: NonNullable<Props['package']>;
}) {
    const t = useT();
    const trend = useTrend();

    return (
        <div className="flex flex-col gap-2">
            <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,160px),1fr))] gap-4">
                <StatCard
                    label={t('connectors.packagist.cards.downloads')}
                    value={formatNumber(summary.downloads)}
                    note={trend(summary.downloads, summary.previous)}
                />
                <StatCard
                    label={t('connectors.packagist.cards.total')}
                    value={formatNumber(summary.total)}
                />
                <StatCard
                    label={t('connectors.packagist.cards.favers')}
                    value={formatNumber(summary.favers)}
                />
                <StatCard
                    label={t('connectors.packagist.cards.dependents')}
                    value={formatNumber(summary.dependents)}
                />
                <StatCard
                    label={t('connectors.packagist.cards.suggesters')}
                    value={formatNumber(summary.suggesters)}
                />
                {summary.github_stars !== null && (
                    <StatCard
                        label={t('connectors.packagist.cards.stars')}
                        value={formatNumber(summary.github_stars)}
                    />
                )}
                <StatCard
                    label={t('connectors.packagist.cards.advisories')}
                    value={formatNumber(summary.advisories)}
                    tone={summary.advisories > 0 ? 'warn' : undefined}
                />
            </div>
            <p className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-3">
                {summary.through && (
                    <span>
                        {t('connectors.packagist.through', {
                            date: formatDate(summary.through),
                        })}
                    </span>
                )}
                <a
                    href={pkg.url}
                    className={link}
                    target="_blank"
                    rel="noreferrer"
                >
                    {t('connectors.packagist.open')}
                    <ExternalLink className="size-3" aria-hidden="true" />
                </a>
                {pkg.repository && (
                    <a
                        href={pkg.repository}
                        className={link}
                        target="_blank"
                        rel="noreferrer"
                    >
                        {t('connectors.packagist.repository')}
                        <ExternalLink className="size-3" aria-hidden="true" />
                    </a>
                )}
            </p>
        </div>
    );
}

function Downloads({
    series,
    period,
}: {
    series: Props['series'];
    period: Period;
}) {
    const t = useT();
    const total = series.reduce((sum, point) => sum + point.downloads, 0);

    return (
        <Card aria-labelledby="packagist-series">
            <CardHeader
                id="packagist-series"
                title={t('connectors.packagist.series.title')}
                description={t('connectors.packagist.series.description')}
            />
            <div className="p-4">
                <TimeBars
                    series={series.map((point) => ({
                        t: point.t,
                        v: point.downloads,
                    }))}
                    period={period}
                    color="var(--lj-info)"
                    height={140}
                    formatValue={(v) => formatNumber(v)}
                    label={t('connectors.packagist.series.summary', {
                        count: formatNumber(total),
                    })}
                />
            </div>
        </Card>
    );
}

/** Downloads by major version, with a bar of each one's share. */
function Majors({ majors }: { majors: Props['majors'] }) {
    const t = useT();
    const shown = majors.filter((m) => m.downloads > 0);

    return (
        <Card aria-labelledby="packagist-majors">
            <CardHeader
                id="packagist-majors"
                title={t('connectors.packagist.majors.title')}
                description={t('connectors.packagist.majors.description')}
            />
            {shown.length === 0 ? (
                <EmptyState title={t('connectors.packagist.majors.empty')} />
            ) : (
                <div className="relative overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>
                                    {t('connectors.packagist.majors.major')}
                                </th>
                                <th className={cn(thClass, 'text-right')}>
                                    {t('connectors.packagist.majors.downloads')}
                                </th>
                                <th className={cn(thClass, 'w-1/2')}>
                                    {t('connectors.packagist.majors.share')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((m) => (
                                <tr key={m.major}>
                                    <td className={cn(tdClass, 'font-mono')}>
                                        {m.major}
                                    </td>
                                    <td className={cn(tdClass, numClass)}>
                                        {formatNumber(m.downloads)}
                                    </td>
                                    <td className={tdClass}>
                                        <div className="flex items-center gap-2">
                                            <div
                                                aria-hidden="true"
                                                className="h-1.5 flex-1 overflow-hidden rounded-full bg-panel-2"
                                            >
                                                <div
                                                    className="h-full rounded-full bg-info"
                                                    style={{
                                                        width: `${m.share * 100}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="w-12 text-right font-mono text-xs text-ink-2">
                                                {formatPercent(m.share, 0)}
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

function Releases({ releases }: { releases: Props['releases'] }) {
    const t = useT();

    return (
        <Card aria-labelledby="packagist-releases">
            <CardHeader
                id="packagist-releases"
                title={t('connectors.packagist.releases.title')}
                description={t('connectors.packagist.releases.description')}
            />
            {releases.length === 0 ? (
                <EmptyState title={t('connectors.packagist.releases.empty')} />
            ) : (
                <div className="relative overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>
                                    {t('connectors.packagist.releases.version')}
                                </th>
                                <th className={thClass}>
                                    {t(
                                        'connectors.packagist.releases.released',
                                    )}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {releases.map((r) => (
                                <tr key={r.version}>
                                    <td className={cn(tdClass, 'font-mono')}>
                                        {r.version}
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            'text-xs text-ink-2',
                                        )}
                                    >
                                        {r.time ? formatDate(r.time) : '-'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

function Advisories({ advisories }: { advisories: Advisory[] }) {
    const t = useT();

    return (
        <Card aria-labelledby="packagist-advisories">
            <CardHeader
                id="packagist-advisories"
                title={t('connectors.packagist.advisories.title')}
                description={t('connectors.packagist.advisories.description')}
            />
            {advisories.length === 0 ? (
                <EmptyState
                    title={t('connectors.packagist.advisories.empty')}
                />
            ) : (
                <div className="relative overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>
                                    {t(
                                        'connectors.packagist.advisories.advisory',
                                    )}
                                </th>
                                <th className={thClass}>
                                    {t(
                                        'connectors.packagist.advisories.severity',
                                    )}
                                </th>
                                <th className={thClass}>
                                    {t(
                                        'connectors.packagist.advisories.affected',
                                    )}
                                </th>
                                <th className={thClass}>
                                    {t(
                                        'connectors.packagist.advisories.reported',
                                    )}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {advisories.map((a) => (
                                <tr key={a.id} className="align-top">
                                    <td className={cn(tdClass, 'min-w-64')}>
                                        {a.link ? (
                                            <a
                                                href={a.link}
                                                className={link}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                {a.title}
                                                <ExternalLink
                                                    className="size-3 shrink-0"
                                                    aria-hidden="true"
                                                />
                                            </a>
                                        ) : (
                                            a.title
                                        )}
                                        {a.cve && (
                                            <span className="block font-mono text-xs text-ink-3">
                                                {a.cve}
                                            </span>
                                        )}
                                    </td>
                                    <td className={tdClass}>
                                        <Badge
                                            tone={
                                                SEVERITY_TONES[
                                                    a.severity ?? ''
                                                ] ?? 'neutral'
                                            }
                                        >
                                            {t(
                                                `connectors.packagist.advisories.severities.${a.severity ?? 'unknown'}`,
                                            )}
                                        </Badge>
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            'font-mono text-xs break-all text-ink-2',
                                        )}
                                    >
                                        {a.affected.split('|').join(' | ')}
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            'text-xs whitespace-nowrap text-ink-2',
                                        )}
                                    >
                                        {a.reported_at
                                            ? formatDate(a.reported_at)
                                            : '-'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

function NotConnected({ project }: { project: ProjectHeader }) {
    const t = useT();
    const [connecting, setConnecting] = useState(false);

    return (
        <Card>
            <EmptyState title={t('connectors.packagist.not_connected.title')}>
                <p>
                    {project.can_administer
                        ? t('connectors.packagist.not_connected.body')
                        : t('connectors.packagist.not_connected.members')}
                </p>
                {project.can_administer && (
                    <Button
                        variant="primary"
                        className="mt-3"
                        onClick={() => setConnecting(true)}
                    >
                        {t('connectors.packagist.not_connected.title')}
                    </Button>
                )}
            </EmptyState>
            {connecting && (
                <PackagistConnectDialog
                    project={project.uuid}
                    onClose={() => setConnecting(false)}
                />
            )}
        </Card>
    );
}
