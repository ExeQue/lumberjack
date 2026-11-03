import { MessageSquare } from 'lucide-react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    Card,
    EmptyState,
    Input,
    PageTitle,
    Segmented,
    Select,
    Sparkline,
    spreadTips,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import { formatNumber, formatRelative, formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { useProjectLive } from '@/lib/use-project-live';
import {
    bulkStatus,
    index as issuesIndex,
    show as issueShow,
} from '@/routes/issues';
import { show as projectShow } from '@/routes/projects';
import type {
    IssueSummary,
    Paginated,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';
import { LabelChip } from '@/components/lj/labels';
import type { IssueLabel } from '@/components/lj/labels';

type Row = IssueSummary & { hourly: number[] };

type Filters = {
    status: string;
    source: string | null;
    search: string;
    release: string;
    assigned: string;
    sort: string;
    label: string;
};

type Props = {
    project: ProjectHeader;
    filters: Filters;
    counts: Record<string, number>;
    releases: string[];
    labels: IssueLabel[];
    issues: Paginated<Row>;
};

export default function IssuesIndex({
    project,
    filters,
    counts,
    releases,
    labels,
    issues,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [selected, setSelected] = useState<string[]>([]);
    const [search, setSearch] = useState(filters.search);

    useProjectLive(
        project.id,
        ['issues.changed', 'issue.updated'],
        ['issues', 'counts'],
    );

    const apply = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        const query = Object.fromEntries(
            Object.entries(next).filter(
                ([, v]) => v !== null && v !== '' && v !== undefined,
            ),
        ) as Record<string, string>;

        router.get(
            issuesIndex(project.uuid, { query }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(() => apply({ search }), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const allSelected =
        issues.data.length > 0 && selected.length === issues.data.length;

    const bulk = (status: 'resolved' | 'ignored' | 'open') => {
        router.post(
            bulkStatus(project.uuid).url,
            { status, issues: selected },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );
    };

    return (
        <>
            <Head title={`${t('issues.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('issues.title') },
                ]}
            />
            <PageBody wide>
                <PageTitle title={t('issues.title')} />

                <div className="flex flex-wrap items-center gap-2">
                    <Segmented
                        label={t('issues.status')}
                        value={filters.status}
                        onChange={(status) => apply({ status })}
                        options={(
                            [
                                'open',
                                'in_progress',
                                'resolved',
                                'ignored',
                            ] as const
                        ).map((status) => ({
                            value: status,
                            label: (
                                <>
                                    {t(`common.statuses.${status}`)}{' '}
                                    <span className="font-mono">
                                        {formatNumber(counts[status] ?? 0)}
                                    </span>
                                </>
                            ),
                        }))}
                    />
                    <Segmented
                        label={t('issues.source')}
                        value={filters.source ?? ''}
                        onChange={(source) => apply({ source: source || null })}
                        options={[
                            { value: '', label: t('issues.all_sources') },
                            ...(['server', 'browser', 'log'] as const).map(
                                (s) => ({
                                    value: s,
                                    label: t(`common.sources.${s}`),
                                }),
                            ),
                        ]}
                    />
                    <label className="sr-only" htmlFor="issue-release">
                        {t('issues.release')}
                    </label>
                    <Select
                        id="issue-release"
                        value={filters.release}
                        onChange={(e) => apply({ release: e.target.value })}
                    >
                        <option value="">{t('issues.all_releases')}</option>
                        {releases.map((release) => (
                            <option key={release} value={release}>
                                {release}
                            </option>
                        ))}
                    </Select>
                    <label className="sr-only" htmlFor="issue-assigned">
                        {t('issues.assigned')}
                    </label>
                    <Select
                        id="issue-assigned"
                        value={filters.assigned}
                        onChange={(e) => apply({ assigned: e.target.value })}
                    >
                        <option value="">{t('issues.assigned_all')}</option>
                        <option value="me">{t('issues.assigned_me')}</option>
                        <option value="none">
                            {t('issues.assigned_none')}
                        </option>
                    </Select>
                    {labels.length > 0 && (
                        <>
                            <label className="sr-only" htmlFor="issue-label">
                                {t('issues.label')}
                            </label>
                            <Select
                                id="issue-label"
                                value={filters.label}
                                onChange={(e) =>
                                    apply({ label: e.target.value })
                                }
                            >
                                <option value="">
                                    {t('issues.all_labels')}
                                </option>
                                {labels.map((label) => (
                                    <option key={label.id} value={label.name}>
                                        {label.name}
                                    </option>
                                ))}
                            </Select>
                        </>
                    )}
                    <label className="sr-only" htmlFor="issue-search">
                        {t('issues.search')}
                    </label>
                    <Input
                        id="issue-search"
                        type="search"
                        placeholder={t('issues.search')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="min-w-44 flex-1"
                    />
                    <label className="sr-only" htmlFor="issue-sort">
                        {t('issues.sort.label')}
                    </label>
                    <Select
                        id="issue-sort"
                        value={filters.sort}
                        onChange={(e) => apply({ sort: e.target.value })}
                    >
                        <option value="last_seen">
                            {t('issues.sort.last_seen')}
                        </option>
                        <option value="occurrences">
                            {t('issues.sort.occurrences')}
                        </option>
                        <option value="first_seen">
                            {t('issues.sort.first_seen')}
                        </option>
                    </Select>
                </div>

                <Card className="overflow-x-auto">
                    {issues.data.length === 0 ? (
                        <EmptyState title={t('issues.empty_title')}>
                            {t('issues.empty_body')}
                        </EmptyState>
                    ) : (
                        <table className={tableClass}>
                            <thead>
                                <tr>
                                    <th className={`${thClass} w-8`}>
                                        <label
                                            className="sr-only"
                                            htmlFor="select-all"
                                        >
                                            {t('issues.select_all')}
                                        </label>
                                        <input
                                            id="select-all"
                                            type="checkbox"
                                            checked={allSelected}
                                            onChange={() =>
                                                setSelected(
                                                    allSelected
                                                        ? []
                                                        : issues.data.map(
                                                              (i) => i.id,
                                                          ),
                                                )
                                            }
                                        />
                                    </th>
                                    <th className={thClass}>
                                        {t('issues.columns.issue')}
                                    </th>
                                    <th className={thClass}>
                                        {t('issues.columns.last_24h')}
                                    </th>
                                    <th className={`${thClass} text-right`}>
                                        {t('issues.columns.occurrences')}
                                    </th>
                                    <th className={thClass}>
                                        {t('issues.columns.last_seen')}
                                    </th>
                                    <th className={thClass}>
                                        {t('issues.columns.first_seen')}
                                    </th>
                                    <th className={thClass}>
                                        {t('issues.columns.assignee')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {issues.data.map((issue) => (
                                    <tr
                                        key={issue.id}
                                        className="hover:bg-panel-2/60"
                                    >
                                        <td className={`${tdClass} align-top`}>
                                            <label
                                                className="sr-only"
                                                htmlFor={`select-${issue.id}`}
                                            >
                                                {t('issues.select_issue', {
                                                    title: issue.title,
                                                })}
                                            </label>
                                            <input
                                                id={`select-${issue.id}`}
                                                type="checkbox"
                                                checked={selected.includes(
                                                    issue.id,
                                                )}
                                                onChange={() =>
                                                    setSelected((current) =>
                                                        current.includes(
                                                            issue.id,
                                                        )
                                                            ? current.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      issue.id,
                                                              )
                                                            : [
                                                                  ...current,
                                                                  issue.id,
                                                              ],
                                                    )
                                                }
                                            />
                                        </td>
                                        <td
                                            className={`${tdClass} min-w-[360px] align-top`}
                                        >
                                            <Link
                                                href={
                                                    issueShow([
                                                        project.uuid,
                                                        issue.id,
                                                    ]).url
                                                }
                                                className="flex flex-col gap-1 text-ink hover:text-amber"
                                            >
                                                <span className="flex flex-wrap items-center gap-2">
                                                    {issue.reference && (
                                                        <span className="font-mono text-xs text-ink-3">
                                                            {issue.reference}
                                                        </span>
                                                    )}
                                                    <span className="font-mono font-medium">
                                                        {issue.title}
                                                    </span>
                                                    <Badge>
                                                        {t(
                                                            `common.sources.${issue.source}`,
                                                        )}
                                                    </Badge>
                                                    {issue.returned && (
                                                        <Badge tone="warn">
                                                            {t(
                                                                'common.flags.returned',
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {issue.escalating && (
                                                        <Badge tone="err">
                                                            {t(
                                                                'common.flags.escalating',
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {issue.regressed && (
                                                        <Badge tone="err">
                                                            {t(
                                                                'common.flags.regression',
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {issue.status ===
                                                        'in_progress' && (
                                                        <Badge tone="info">
                                                            {t(
                                                                'common.statuses.in_progress',
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {!issue.regressed &&
                                                        issue.new && (
                                                            <Badge tone="info">
                                                                {t(
                                                                    'common.flags.new',
                                                                )}
                                                            </Badge>
                                                        )}
                                                    {issue.labels.map(
                                                        (label) => (
                                                            <LabelChip
                                                                key={label.id}
                                                                label={label}
                                                            />
                                                        ),
                                                    )}
                                                    {issue.notes > 0 && (
                                                        <Badge
                                                            title={t(
                                                                'issues.notes_count',
                                                                {},
                                                                issue.notes,
                                                            )}
                                                        >
                                                            <MessageSquare
                                                                className="size-3"
                                                                aria-hidden="true"
                                                            />
                                                            {issue.notes}
                                                            <span className="sr-only">
                                                                {t(
                                                                    'issues.notes_count',
                                                                    {},
                                                                    issue.notes,
                                                                )}
                                                            </span>
                                                        </Badge>
                                                    )}
                                                </span>
                                                <span className="line-clamp-2 text-ink-2">
                                                    {issue.message}
                                                </span>
                                                {issue.location && (
                                                    <span className="font-mono text-[11px] text-ink-3">
                                                        {issue.location}
                                                    </span>
                                                )}
                                            </Link>
                                        </td>
                                        <td className={tdClass}>
                                            <Sparkline
                                                values={issue.hourly}
                                                tips={spreadTips(
                                                    issue.hourly,
                                                    '24h',
                                                    formatNumber,
                                                )}
                                                label={t('issues.trend', {
                                                    count: issue.hourly.reduce(
                                                        (a, b) => a + b,
                                                        0,
                                                    ),
                                                })}
                                            />
                                        </td>
                                        <td
                                            className={`${tdClass} ${numClass}`}
                                        >
                                            {formatNumber(issue.occurrences)}
                                        </td>
                                        <td
                                            className={`${tdClass} whitespace-nowrap text-ink-2`}
                                        >
                                            <time
                                                dateTime={issue.last_seen_at}
                                                title={formatDateTime(
                                                    issue.last_seen_at,
                                                )}
                                            >
                                                {formatRelative(
                                                    issue.last_seen_at,
                                                )}
                                            </time>
                                        </td>
                                        <td
                                            className={`${tdClass} whitespace-nowrap text-ink-2`}
                                        >
                                            {formatDateTime(
                                                issue.first_seen_at,
                                            )}
                                        </td>
                                        <td className={`${tdClass} text-ink-2`}>
                                            {issue.assignee?.name ??
                                                t('common.unassigned')}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>

                <div className="flex flex-wrap items-center justify-between gap-3 text-ink-3">
                    <span>
                        {t('common.showing', {
                            from:
                                issues.total === 0
                                    ? 0
                                    : (issues.current_page - 1) *
                                          issues.per_page +
                                      1,
                            to: Math.min(
                                issues.current_page * issues.per_page,
                                issues.total,
                            ),
                            total: issues.total,
                        })}
                    </span>
                    <div className="flex flex-wrap gap-2">
                        {filters.status !== 'resolved' && (
                            <Button
                                disabled={selected.length === 0}
                                onClick={() => bulk('resolved')}
                            >
                                {t('issues.resolve_selected')}
                            </Button>
                        )}
                        {filters.status !== 'ignored' && (
                            <Button
                                disabled={selected.length === 0}
                                onClick={() => bulk('ignored')}
                            >
                                {t('issues.ignore_selected')}
                            </Button>
                        )}
                        {filters.status !== 'open' && (
                            <Button
                                disabled={selected.length === 0}
                                onClick={() => bulk('open')}
                            >
                                {t('issues.reopen_selected')}
                            </Button>
                        )}
                        {issues.prev_page_url && (
                            <Link
                                href={issues.prev_page_url}
                                className="inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 text-ink"
                            >
                                {t('common.previous')}
                            </Link>
                        )}
                        {issues.next_page_url && (
                            <Link
                                href={issues.next_page_url}
                                className="inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 text-ink"
                            >
                                {t('common.next')}
                            </Link>
                        )}
                    </div>
                </div>
            </PageBody>
        </>
    );
}
