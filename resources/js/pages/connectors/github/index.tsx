import { Form, Head, router, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import Markdown from 'react-markdown';
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
    Field,
    Input,
    PageTitle,
    Segmented,
    Select,
    StatCard,
    tableClass,
    tdClass,
    thClass,
    TimeBars,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatNumber, formatRelative } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { index as githubIndex } from '@/routes/connectors/github';
import { update as connectionUpdate } from '@/routes/connectors/github/connection';
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
    /** The release's notes as written, in Markdown. */
    body: string;
};

type PullRequest = {
    number: number;
    title: string;
    author: string;
    draft: boolean;
    url: string;
    created_at: string | null;
};

type Commit = {
    sha: string;
    message: string;
    author: string;
    url: string;
    committed_at: string;
};

type Commits = {
    count: { current: number; previous: number };
    authors: number;
    series: { t: string; commits: number }[];
    contributors: { author: string; commits: number }[];
    latest: Commit[];
    /** Whether the period has more commits than are read. */
    capped: boolean;
};

type SecurityKind = {
    kind: string;
    label: string;
    /** Whether the token had the permission when it was connected. */
    granted: boolean;
    count: number | null;
    by_severity: Record<string, number> | null;
    /** Why there is no count, such as the feature being off. */
    error: string | null;
};

type Permission = {
    key: string;
    label: string;
    unlocks: string;
    granted: boolean;
};

type Props = {
    project: ProjectHeader;
    filters: { period: Period };
    /** How many rows each list shows, when more than at first. */
    rows: Partial<Record<List, number>>;
    connection: {
        uuid: string;
        name: string;
        url: string;
        /** The branch commits and CI are read from; null for the default branch. */
        branch: string | null;
        /** Deferred, for those who administer the project; null when GitHub did not answer. */
        default_branch?: string | null;
        /** Deferred: the branches to suggest, the default first. */
        branches?: string[];
    } | null;
    /** Every repository the project reads, by the uuid of its connection. */
    repositories: { uuid: string; name: string }[];
    error: string | null;
    summary: {
        open_pull_requests: number;
        waiting_for_review: number;
        open_issues: number;
        stars: number;
        forks: number;
        watchers: number;
        merged: { current: number; previous: number };
        lead_time_hours: { current: number | null; previous: number | null };
        failed_runs: { current: number; previous: number };
        ci: Run | null;
        latest_release: Release | null;
    } | null;
    pull_requests: PullRequest[];
    runs: Run[];
    releases: Release[];
    commits: Commits | null;
    /** Null when the token does not read issues. */
    issues: {
        opened: { current: number; previous: number };
        closed: { current: number; previous: number };
        open_bugs: number;
    } | null;
    security: SecurityKind[];
    permissions: Permission[];
};

const PERIODS: Period[] = ['1h', '24h', '7d', '30d'];

const TABS = [
    'pull_requests',
    'commits',
    'actions',
    'releases',
    'security',
    'connection',
] as const;

/** Members see the repository but not where it is connected. */
const MEMBER_TABS = [
    'pull_requests',
    'commits',
    'actions',
    'releases',
    'security',
] as const;

/**
 * Each list's rows at first, which is also how many each Load more adds, and the most it
 * loads, as the controller holds them; and the prop each list comes in.
 */
const LISTS = {
    pull_requests: { rows: 20, max: 100, prop: 'pull_requests' },
    runs: { rows: 20, max: 100, prop: 'runs' },
    releases: { rows: 20, max: 100, prop: 'releases' },
    commits: { rows: 20, max: 1000, prop: 'commits' },
    contributors: { rows: 10, max: 100, prop: 'commits' },
} as const;

type List = keyof typeof LISTS;

/** Shows the next rows of a list, partially reloading only its prop. */
type LoadMore = (list: List) => void;

/** Dependabot severities, the worst first. */
const SEVERITIES = ['critical', 'high', 'medium', 'low'] as const;

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
    rows,
    connection,
    repositories,
    error,
    summary,
    pull_requests,
    runs,
    releases,
    commits,
    issues,
    security,
    permissions,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const tabs: readonly Tab[] = project.can_administer ? TABS : MEMBER_TABS;
    const [tab, setTab] = useHashTab<Tab>(tabs);

    /**
     * Visits the page as changed. The rows loaded are kept in the query until the repository
     * or period changes.
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
                ? githubIndex([project.uuid, chosen], { query }).url
                : githubIndex({ project: project.uuid }, { query }).url) +
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
            [LISTS[list].prop, 'rows'],
        );

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
                            <Summary
                                summary={summary}
                                commits={commits}
                                issues={issues}
                            />
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
                                <PullRequests
                                    pulls={pull_requests}
                                    shown={rows}
                                    onMore={loadMore}
                                />
                            )}
                        </ConnectorPanel>
                        <ConnectorPanel
                            id="github"
                            name="commits"
                            current={tab}
                        >
                            {error === null && commits && (
                                <CommitsPanel
                                    branch={connection.branch}
                                    commits={commits}
                                    period={filters.period}
                                    shown={rows}
                                    onMore={loadMore}
                                />
                            )}
                        </ConnectorPanel>
                        <ConnectorPanel
                            id="github"
                            name="actions"
                            current={tab}
                        >
                            {error === null && (
                                <Runs
                                    runs={runs}
                                    shown={rows}
                                    onMore={loadMore}
                                />
                            )}
                        </ConnectorPanel>
                        <ConnectorPanel
                            id="github"
                            name="releases"
                            current={tab}
                        >
                            {error === null && (
                                <Releases
                                    releases={releases}
                                    shown={rows}
                                    onMore={loadMore}
                                />
                            )}
                        </ConnectorPanel>
                        <ConnectorPanel
                            id="github"
                            name="security"
                            current={tab}
                        >
                            {error === null && <Security kinds={security} />}
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
                                    permissions={permissions}
                                />
                            </ConnectorPanel>
                        )}
                    </>
                )}
            </PageBody>
        </>
    );
}

function Summary({
    summary,
    commits,
    issues,
}: {
    summary: NonNullable<Props['summary']>;
    commits: Commits | null;
    issues: Props['issues'];
}) {
    const t = useT();
    const trend = useTrend();
    const { ci, latest_release: release } = summary;
    const ciTone = ci ? resultTone(ci.result) : 'neutral';
    const lead = summary.lead_time_hours;

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
                label={t('connectors.github.cards.lead_time')}
                value={
                    lead.current === null
                        ? '-'
                        : t('connectors.github.cards.hours', {
                              hours: formatNumber(lead.current, 1),
                          })
                }
                note={trend(lead.current, lead.previous)}
            />
            {commits && (
                <>
                    <StatCard
                        label={t('connectors.github.cards.commits')}
                        value={formatNumber(commits.count.current)}
                        note={trend(
                            commits.count.current,
                            commits.count.previous,
                        )}
                    />
                    <StatCard
                        label={t('connectors.github.cards.authors')}
                        value={formatNumber(commits.authors)}
                    />
                </>
            )}
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
            {issues && (
                <>
                    <StatCard
                        label={t('connectors.github.cards.issues_opened')}
                        value={formatNumber(issues.opened.current)}
                        note={trend(
                            issues.opened.current,
                            issues.opened.previous,
                        )}
                    />
                    <StatCard
                        label={t('connectors.github.cards.issues_closed')}
                        value={formatNumber(issues.closed.current)}
                        tone="ok"
                        note={trend(
                            issues.closed.current,
                            issues.closed.previous,
                        )}
                    />
                    <StatCard
                        label={t('connectors.github.cards.open_bugs')}
                        value={formatNumber(issues.open_bugs)}
                        tone={issues.open_bugs > 0 ? 'warn' : undefined}
                    />
                </>
            )}
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
            <StatCard
                label={t('connectors.github.cards.stars')}
                value={formatNumber(summary.stars)}
                note={t('connectors.github.cards.forks_watchers', {
                    forks: formatNumber(summary.forks),
                    watchers: formatNumber(summary.watchers),
                })}
            />
        </div>
    );
}

/** Commits over the period, who made the most, and the latest. */
function CommitsPanel({
    branch,
    commits,
    period,
    shown,
    onMore,
}: {
    branch: string | null;
    commits: Commits;
    period: Period;
    shown: Props['rows'];
    onMore: LoadMore;
}) {
    const t = useT();
    const description =
        branch === null
            ? t('connectors.github.commits.description')
            : t('connectors.github.commits.description_branch', { branch });

    return (
        <div className="flex flex-col gap-4">
            <Card aria-labelledby="github-commits">
                <CardHeader
                    id="github-commits"
                    title={t('connectors.github.commits.title')}
                    description={
                        commits.capped
                            ? `${description} ${t(
                                  'connectors.github.commits.capped',
                                  { max: formatNumber(commits.count.current) },
                              )}`
                            : description
                    }
                />
                <div className="p-4">
                    <TimeBars
                        series={commits.series.map((point) => ({
                            t: point.t,
                            v: point.commits,
                        }))}
                        period={period}
                        height={140}
                        formatValue={(v) => formatNumber(v)}
                        label={t('connectors.github.commits.summary', {
                            commits: formatNumber(commits.count.current),
                            authors: formatNumber(commits.authors),
                        })}
                    />
                </div>
            </Card>
            <Card aria-labelledby="github-contributors">
                <CardHeader
                    id="github-contributors"
                    title={t('connectors.github.commits.contributors')}
                    description={t(
                        'connectors.github.commits.contributors_description',
                    )}
                />
                {commits.contributors.length === 0 ? (
                    <EmptyState title={t('connectors.github.commits.empty')} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableClass}>
                            <thead>
                                <tr>
                                    <th className={thClass}>
                                        {t('connectors.github.commits.author')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('connectors.github.commits.count')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {commits.contributors.map((row) => (
                                    <tr key={row.author}>
                                        <td className={`${tdClass} font-mono`}>
                                            {row.author}
                                        </td>
                                        <td
                                            className={`${tdClass} text-right tabular-nums`}
                                        >
                                            {formatNumber(row.commits)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <More
                    list="contributors"
                    count={commits.contributors.length}
                    shown={shown}
                    onMore={onMore}
                />
            </Card>
            <Card aria-labelledby="github-latest-commits">
                <CardHeader
                    id="github-latest-commits"
                    title={t('connectors.github.commits.latest')}
                    description={t(
                        'connectors.github.commits.latest_description',
                    )}
                />
                {commits.latest.length === 0 ? (
                    <EmptyState title={t('connectors.github.commits.empty')} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableClass}>
                            <thead>
                                <tr>
                                    <th className={thClass}>
                                        {t('connectors.github.commits.commit')}
                                    </th>
                                    <th className={thClass}>
                                        {t('connectors.github.commits.author')}
                                    </th>
                                    <th className={thClass}>
                                        {t(
                                            'connectors.github.commits.committed',
                                        )}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {commits.latest.map((commit) => (
                                    <tr key={commit.sha}>
                                        <td className={tdClass}>
                                            <External href={commit.url}>
                                                <span className="font-mono text-ink-3">
                                                    {commit.sha.slice(0, 7)}
                                                </span>{' '}
                                                {commit.message}
                                            </External>
                                        </td>
                                        <td className={`${tdClass} font-mono`}>
                                            {commit.author}
                                        </td>
                                        <td
                                            className={`${tdClass} whitespace-nowrap`}
                                        >
                                            {formatRelative(
                                                commit.committed_at,
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <More
                    list="commits"
                    count={commits.latest.length}
                    shown={shown}
                    onMore={onMore}
                />
            </Card>
        </div>
    );
}

/** Open alerts of each security feature, or why there is no count. */
function Security({ kinds }: { kinds: SecurityKind[] }) {
    const t = useT();

    return (
        <Card aria-labelledby="github-security">
            <CardHeader
                id="github-security"
                title={t('connectors.github.security.title')}
                description={t('connectors.github.security.description')}
            />
            <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,220px),1fr))] gap-4 p-4">
                {kinds.map((kind) => (
                    <StatCard
                        key={kind.kind}
                        label={t(
                            `connectors.github.security.kinds.${kind.kind}`,
                        )}
                        value={
                            kind.count === null ? (
                                <span className="text-base">-</span>
                            ) : (
                                formatNumber(kind.count)
                            )
                        }
                        tone={
                            kind.count !== null && kind.count > 0
                                ? 'err'
                                : undefined
                        }
                        note={
                            !kind.granted
                                ? t(
                                      'connectors.github.security.no_permission',
                                      {
                                          permission: kind.label,
                                      },
                                  )
                                : (kind.error ??
                                  (kind.by_severity
                                      ? SEVERITIES.map(
                                            (severity) =>
                                                `${t(`connectors.github.security.severities.${severity}`)} ${formatNumber(kind.by_severity?.[severity] ?? 0)}`,
                                        ).join(', ')
                                      : ''))
                        }
                    />
                ))}
            </div>
        </Card>
    );
}

/** A list's Load more, below it while a full page came back and the list is not at its most. */
function More({
    list,
    count,
    shown,
    onMore,
}: {
    list: List;
    count: number;
    shown: Props['rows'];
    onMore: LoadMore;
}) {
    const t = useT();
    const limit = shown[list] ?? LISTS[list].rows;

    if (count < limit || limit >= LISTS[list].max) {
        return null;
    }

    return (
        <div className="border-t border-line p-2 text-center">
            <Button variant="ghost" onClick={() => onMore(list)}>
                {t('connectors.github.load_more')}
            </Button>
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

function PullRequests({
    pulls,
    shown,
    onMore,
}: {
    pulls: PullRequest[];
    shown: Props['rows'];
    onMore: LoadMore;
}) {
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
            <More
                list="pull_requests"
                count={pulls.length}
                shown={shown}
                onMore={onMore}
            />
        </Card>
    );
}

function Runs({
    runs,
    shown,
    onMore,
}: {
    runs: Run[];
    shown: Props['rows'];
    onMore: LoadMore;
}) {
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
            <More
                list="runs"
                count={runs.length}
                shown={shown}
                onMore={onMore}
            />
        </Card>
    );
}

function Releases({
    releases,
    shown,
    onMore,
}: {
    releases: Release[];
    shown: Props['rows'];
    onMore: LoadMore;
}) {
    const t = useT();
    const [open, setOpen] = useState<string[]>([]);
    const toggle = (url: string) =>
        setOpen((urls) =>
            urls.includes(url) ? urls.filter((u) => u !== url) : [...urls, url],
        );

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
                            {releases.map((release, i) => {
                                const expanded = open.includes(release.url);
                                const notes = `github-release-notes-${i}`;

                                return (
                                    <Fragment key={release.url}>
                                        <tr>
                                            <td className={tdClass}>
                                                <span className="inline-flex flex-wrap items-center gap-2">
                                                    {release.body !== '' && (
                                                        <button
                                                            type="button"
                                                            aria-expanded={
                                                                expanded
                                                            }
                                                            aria-controls={
                                                                notes
                                                            }
                                                            aria-label={t(
                                                                'connectors.github.releases.notes',
                                                                {
                                                                    name: release.name,
                                                                },
                                                            )}
                                                            onClick={() =>
                                                                toggle(
                                                                    release.url,
                                                                )
                                                            }
                                                            className="rounded p-0.5 text-ink-3 hover:bg-panel-2 hover:text-ink"
                                                        >
                                                            <ChevronRight
                                                                className={`size-3 transition-transform ${expanded ? 'rotate-90' : ''}`}
                                                                aria-hidden="true"
                                                            />
                                                        </button>
                                                    )}
                                                    <External
                                                        href={release.url}
                                                    >
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
                                            <td
                                                className={`${tdClass} font-mono`}
                                            >
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
                                        {expanded && (
                                            <tr id={notes}>
                                                <td
                                                    colSpan={3}
                                                    className={tdClass}
                                                >
                                                    <ReleaseNotes
                                                        body={release.body}
                                                    />
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
            <More
                list="releases"
                count={releases.length}
                shown={shown}
                onMore={onMore}
            />
        </Card>
    );
}

/**
 * A release's notes, rendered as the About page renders Lumberjack's own: react-markdown
 * makes React elements and escapes any HTML in them, so nothing is injected.
 */
function ReleaseNotes({ body }: { body: string }) {
    return (
        <div className="flex flex-col gap-2 text-sm break-words text-ink-2 [&_code]:rounded [&_code]:bg-panel-2 [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs [&_h1]:font-medium [&_h1]:text-ink [&_h2]:font-medium [&_h2]:text-ink [&_h3]:mt-2 [&_h3]:text-xs [&_h3]:font-medium [&_h3]:tracking-wide [&_h3]:text-ink-3 [&_h3]:uppercase [&_li]:ml-4 [&_li]:list-disc [&_pre]:overflow-x-auto [&_strong]:text-ink [&_ul]:flex [&_ul]:flex-col [&_ul]:gap-1">
            <Markdown
                components={{
                    a: ({ href, children }) => (
                        <a
                            href={href}
                            className="text-amber hover:underline"
                            target="_blank"
                            rel="noreferrer"
                        >
                            {children}
                        </a>
                    ),
                }}
            >
                {body}
            </Markdown>
        </div>
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
    permissions,
}: {
    project: string;
    connection: NonNullable<Props['connection']>;
    permissions: Permission[];
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
        >
            <Form
                {...connectionUpdate.form([project, connection.uuid])}
                options={{ preserveScroll: true }}
                className="flex flex-wrap items-end gap-2"
            >
                {({ errors, processing }) => (
                    <>
                        <Field
                            label={t('connectors.github.settings.branch')}
                            htmlFor="github-settings-branch"
                            hint={t('connectors.github.settings.branch_hint')}
                            error={errors.branch}
                        >
                            <Input
                                id="github-settings-branch"
                                name="branch"
                                maxLength={255}
                                defaultValue={connection.branch ?? ''}
                                placeholder={
                                    connection.default_branch ??
                                    t(
                                        'connectors.github.connect.branch_placeholder',
                                    )
                                }
                                list="github-settings-branches"
                                className="font-mono"
                                autoComplete="off"
                            />
                            <datalist id="github-settings-branches">
                                {connection.branches?.map((name) => (
                                    <option key={name} value={name} />
                                ))}
                            </datalist>
                        </Field>
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={processing}
                        >
                            {t('connectors.github.settings.save_branch')}
                        </Button>
                    </>
                )}
            </Form>
            <section aria-labelledby="github-permissions">
                <h3 id="github-permissions" className="text-sm font-medium">
                    {t('connectors.github.settings.optional')}
                </h3>
                <p className="text-xs text-ink-3">
                    {t('connectors.github.settings.optional_hint')}
                </p>
                <ul className="mt-2 flex flex-col gap-2">
                    {permissions.map((permission) => (
                        <li
                            key={permission.key}
                            className="flex flex-wrap items-center gap-2 text-sm"
                        >
                            <Badge tone={permission.granted ? 'ok' : 'warn'}>
                                {permission.granted
                                    ? t('connectors.github.settings.granted')
                                    : t('connectors.github.settings.missing')}
                            </Badge>
                            <span className="font-medium">
                                {permission.label}
                            </span>
                            <span className="text-ink-3">
                                {permission.unlocks}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>
        </ConnectionCard>
    );
}
