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
import { GitHubConnectDialog } from '@/components/lj/connectors/github/connect';
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
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatNumber, formatRelative } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { index as githubIndex } from '@/routes/connectors/github';
import { show as projectShow } from '@/routes/projects';
import type { Period, ProjectHeader, TeamShared } from '@/types/lumberjack';

type Run = {
    name: string;
    title: string;
    branch: string;
    event: string;
    status: string;
    conclusion: string | null;
    /** What it came to, or how far it got while it runs. */
    result: string;
    result_label: string;
    url: string;
    created_at: string | null;
};

type Release = {
    name: string;
    tag: string;
    prerelease: boolean;
    url: string;
    published_at: string | null;
};

type PullRequest = {
    number: number;
    title: string;
    author: string;
    draft: boolean;
    url: string;
    created_at: string | null;
};

type Props = {
    project: ProjectHeader;
    filters: { period: Period };
    connection: { uuid: string; name: string; url: string } | null;
    /** Every repository the project reads, by the uuid of its connection. */
    repositories: { uuid: string; name: string }[];
    error: string | null;
    summary: {
        open_pull_requests: number;
        waiting_for_review: number;
        open_issues: number;
        merged: { current: number; previous: number };
        failed_runs: { current: number; previous: number };
        ci: Run | null;
        latest_release: Release | null;
    } | null;
    pull_requests: PullRequest[];
    runs: Run[];
    releases: Release[];
};

const PERIODS: Period[] = ['1h', '24h', '7d', '30d'];

const TABS = ['pull_requests', 'actions', 'releases', 'connection'] as const;

/** Members see the repository but not where it is connected. */
const MEMBER_TABS = ['pull_requests', 'actions', 'releases'] as const;

type Tab = (typeof TABS)[number];

/** A run's result as a badge tone; the word beside it says the same. */
function resultTone(result: string): Tone {
    switch (result) {
        case 'success':
            return 'ok';
        case 'failure':
        case 'timed_out':
        case 'startup_failure':
            return 'err';
        case 'cancelled':
        case 'action_required':
            return 'warn';
        default:
            return 'neutral';
    }
}

export default function GitHubIndex({
    project,
    filters,
    connection,
    repositories,
    error,
    summary,
    pull_requests,
    runs,
    releases,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const tabs: readonly Tab[] = project.can_administer ? TABS : MEMBER_TABS;
    const [tab, setTab] = useHashTab<Tab>(tabs);

    const visit = (changes: { period?: Period; connection?: string }) => {
        const period = changes.period ?? filters.period;
        const query = period === '7d' ? {} : { period };
        const chosen = changes.connection ?? connection?.uuid;

        router.get(
            chosen
                ? githubIndex([project.uuid, chosen], { query }).url
                : githubIndex({ project: project.uuid }, { query }).url,
            {},
            { preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`${t('connectors.github.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('connectors.github.title') },
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
                    title={t('connectors.github.title')}
                    description={
                        connection
                            ? t('connectors.github.description', {
                                  name: connection.name,
                              })
                            : t('connectors.github.not_connected.description')
                    }
                    actions={
                        connection &&
                        repositories.length > 1 && (
                            <Select
                                aria-label={t('connectors.github.repository')}
                                value={connection.uuid}
                                className="w-auto"
                                onChange={(e) =>
                                    visit({ connection: e.target.value })
                                }
                            >
                                {repositories.map((r) => (
                                    <option key={r.uuid} value={r.uuid}>
                                        {r.name}
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
                        <ConnectorTabs<Tab>
                            id="github"
                            label={t('connectors.github.tabs.label')}
                            tabs={tabs}
                            current={tab}
                            onChange={setTab}
                            title={(name) =>
                                t(`connectors.github.tabs.${name}`)
                            }
                        />
                        {error !== null && tab !== 'connection' && (
                            <Card>
                                <EmptyState
                                    title={t('connectors.github.error_title')}
                                >
                                    <p>{error}</p>
                                </EmptyState>
                            </Card>
                        )}
                        <ConnectorPanel
                            id="github"
                            name="pull_requests"
                            current={tab}
                        >
                            {error === null && (
                                <PullRequests pulls={pull_requests} />
                            )}
                        </ConnectorPanel>
                        <ConnectorPanel
                            id="github"
                            name="actions"
                            current={tab}
                        >
                            {error === null && <Runs runs={runs} />}
                        </ConnectorPanel>
                        <ConnectorPanel
                            id="github"
                            name="releases"
                            current={tab}
                        >
                            {error === null && <Releases releases={releases} />}
                        </ConnectorPanel>
                        {project.can_administer && (
                            <ConnectorPanel
                                id="github"
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
    const { ci, latest_release: release } = summary;
    const ciTone = ci ? resultTone(ci.result) : 'neutral';

    return (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,160px),1fr))] gap-4">
            <StatCard
                label={t('connectors.github.cards.open_pull_requests')}
                value={formatNumber(summary.open_pull_requests)}
            />
            <StatCard
                label={t('connectors.github.cards.waiting_for_review')}
                value={formatNumber(summary.waiting_for_review)}
                tone={summary.waiting_for_review > 0 ? 'warn' : undefined}
            />
            <StatCard
                label={t('connectors.github.cards.merged')}
                value={formatNumber(summary.merged.current)}
                tone="ok"
                note={trend(summary.merged.current, summary.merged.previous)}
            />
            <StatCard
                label={t('connectors.github.cards.failed_runs')}
                value={formatNumber(summary.failed_runs.current)}
                tone={summary.failed_runs.current > 0 ? 'err' : undefined}
                note={trend(
                    summary.failed_runs.current,
                    summary.failed_runs.previous,
                )}
            />
            <StatCard
                label={t('connectors.github.cards.open_issues')}
                value={formatNumber(summary.open_issues)}
            />
            <StatCard
                label={
                    ci
                        ? t('connectors.github.cards.ci', { branch: ci.branch })
                        : t('connectors.github.cards.ci_none')
                }
                value={
                    <span className="text-base">
                        {ci ? ci.result_label : t('connectors.github.never')}
                    </span>
                }
                tone={
                    ciTone === 'ok' || ciTone === 'err' || ciTone === 'warn'
                        ? ciTone
                        : undefined
                }
                note={ci ? <External href={ci.url}>{ci.name}</External> : ''}
            />
            <StatCard
                label={t('connectors.github.cards.latest_release')}
                value={
                    <span className="text-base">
                        {release ? (
                            <External href={release.url}>
                                {release.name}
                            </External>
                        ) : (
                            t('connectors.github.never')
                        )}
                    </span>
                }
                note={
                    release?.published_at
                        ? formatRelative(release.published_at)
                        : ''
                }
            />
        </div>
    );
}

function External({ href, children }: { href: string; children: ReactNode }) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer"
            className="hover:underline"
        >
            {children}
        </a>
    );
}

function PullRequests({ pulls }: { pulls: PullRequest[] }) {
    const t = useT();

    return (
        <Card aria-labelledby="github-pulls">
            <CardHeader
                id="github-pulls"
                title={t('connectors.github.pull_requests.title')}
                description={t('connectors.github.pull_requests.description')}
            />
            {pulls.length === 0 ? (
                <EmptyState
                    title={t('connectors.github.pull_requests.empty')}
                />
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>
                                    {t(
                                        'connectors.github.pull_requests.pull_request',
                                    )}
                                </th>
                                <th className={thClass}>
                                    {t(
                                        'connectors.github.pull_requests.author',
                                    )}
                                </th>
                                <th className={thClass}>
                                    {t(
                                        'connectors.github.pull_requests.opened',
                                    )}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {pulls.map((pull) => (
                                <tr key={pull.number}>
                                    <td className={tdClass}>
                                        <span className="inline-flex flex-wrap items-center gap-2">
                                            <External href={pull.url}>
                                                <span className="font-mono text-ink-3">
                                                    #{pull.number}
                                                </span>{' '}
                                                {pull.title}
                                            </External>
                                            {pull.draft && (
                                                <Badge>
                                                    {t(
                                                        'connectors.github.pull_requests.draft',
                                                    )}
                                                </Badge>
                                            )}
                                        </span>
                                    </td>
                                    <td className={`${tdClass} font-mono`}>
                                        {pull.author}
                                    </td>
                                    <td
                                        className={`${tdClass} whitespace-nowrap`}
                                    >
                                        {pull.created_at
                                            ? formatRelative(pull.created_at)
                                            : ''}
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

function Runs({ runs }: { runs: Run[] }) {
    const t = useT();

    return (
        <Card aria-labelledby="github-runs">
            <CardHeader
                id="github-runs"
                title={t('connectors.github.actions.title')}
                description={t('connectors.github.actions.description')}
            />
            {runs.length === 0 ? (
                <EmptyState title={t('connectors.github.actions.empty')} />
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>
                                    {t('connectors.github.actions.workflow')}
                                </th>
                                <th className={thClass}>
                                    {t('connectors.github.actions.branch')}
                                </th>
                                <th className={thClass}>
                                    {t('connectors.github.actions.event')}
                                </th>
                                <th className={thClass}>
                                    {t('connectors.github.actions.result')}
                                </th>
                                <th className={thClass}>
                                    {t('connectors.github.actions.started')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {runs.map((run) => (
                                <tr key={run.url}>
                                    <td className={tdClass}>
                                        <External href={run.url}>
                                            {run.name}
                                        </External>
                                        {run.title && (
                                            <span className="block text-xs text-ink-3">
                                                {run.title}
                                            </span>
                                        )}
                                    </td>
                                    <td className={`${tdClass} font-mono`}>
                                        {run.branch}
                                    </td>
                                    <td className={tdClass}>{run.event}</td>
                                    <td className={tdClass}>
                                        <Badge tone={resultTone(run.result)}>
                                            {run.result_label}
                                        </Badge>
                                    </td>
                                    <td
                                        className={`${tdClass} whitespace-nowrap`}
                                    >
                                        {run.created_at
                                            ? formatRelative(run.created_at)
                                            : ''}
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

function Releases({ releases }: { releases: Release[] }) {
    const t = useT();

    return (
        <Card aria-labelledby="github-releases">
            <CardHeader
                id="github-releases"
                title={t('connectors.github.releases.title')}
                description={t('connectors.github.releases.description')}
            />
            {releases.length === 0 ? (
                <EmptyState title={t('connectors.github.releases.empty')} />
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>
                                    {t('connectors.github.releases.release')}
                                </th>
                                <th className={thClass}>
                                    {t('connectors.github.releases.tag')}
                                </th>
                                <th className={thClass}>
                                    {t('connectors.github.releases.published')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {releases.map((release) => (
                                <tr key={release.url}>
                                    <td className={tdClass}>
                                        <span className="inline-flex flex-wrap items-center gap-2">
                                            <External href={release.url}>
                                                {release.name}
                                            </External>
                                            {release.prerelease && (
                                                <Badge tone="info">
                                                    {t(
                                                        'connectors.github.releases.prerelease',
                                                    )}
                                                </Badge>
                                            )}
                                        </span>
                                    </td>
                                    <td className={`${tdClass} font-mono`}>
                                        {release.tag}
                                    </td>
                                    <td
                                        className={`${tdClass} whitespace-nowrap`}
                                    >
                                        {release.published_at
                                            ? formatRelative(
                                                  release.published_at,
                                              )
                                            : ''}
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
            <EmptyState title={t('connectors.github.not_connected.title')}>
                <p>
                    {project.can_administer
                        ? t('connectors.github.not_connected.body')
                        : t('connectors.github.not_connected.members')}
                </p>
                {project.can_administer && (
                    <Button
                        variant="primary"
                        className="mt-3"
                        onClick={() => setConnecting(true)}
                    >
                        {t('connectors.github.not_connected.title')}
                    </Button>
                )}
            </EmptyState>
            {connecting && (
                <GitHubConnectDialog
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
            description={t('connectors.github.settings.body', {
                url: connection.url,
            })}
            connectLabel={t('connectors.github.settings.add')}
            disconnectQuestion={t(
                'connectors.github.settings.disconnect_question',
            )}
            dialog={(onClose) => (
                <GitHubConnectDialog project={project} onClose={onClose} />
            )}
        />
    );
}
