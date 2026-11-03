import { Head, router, useForm, usePage, Link } from '@inertiajs/react';
import { CircleDot } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { LabelEditor } from '@/components/lj/labels';
import type { IssueLabel } from '@/components/lj/labels';
import { MentionTextarea, NoteBody, toStored } from '@/components/lj/mentions';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Mentionable } from '@/components/lj/mentions';
import { CopyText } from '@/components/lj/copy-text';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    TimeBars,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Eyebrow,
    Field,
    Select,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatDateTime,
    formatNumber,
    formatRelative,
    formatTime,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import {
    assign,
    index as issuesIndex,
    repoIssue,
    status as issueStatus,
} from '@/routes/issues';
import { store as storeNote } from '@/routes/issues/notes';
import { show as projectShow } from '@/routes/projects';
import { show as traceShow } from '@/routes/traces';
import type {
    IssueNote,
    IssueSummary,
    Occurrence,
    ProjectHeader,
    SeriesPoint,
    StackFrame,
    TeamShared,
} from '@/types/lumberjack';

type IssueDetail = IssueSummary & {
    hosts: string | null;
    releases: number;
    regressed_at: string | null;
};

type Props = {
    project: ProjectHeader;
    issue: IssueDetail;
    latest: Occurrence | null;
    occurrences: Omit<Occurrence, 'stacktrace'>[];
    series: SeriesPoint[];
    notes: IssueNote[];
    members: { id: number; name: string }[];
    agents: { id: number; name: string }[];
    labels: IssueLabel[];
    history: HistoryEntry[];
    has_repository: boolean;
};

type Tab = 'stacktrace' | 'occurrences' | 'notes' | 'history';

const TABS: Tab[] = ['stacktrace', 'occurrences', 'notes', 'history'];

const statusTone: Record<IssueSummary['status'], Tone> = {
    open: 'err',
    in_progress: 'info',
    resolved: 'ok',
    ignored: 'neutral',
};

function hourLabel(iso: string): string {
    return new Date(iso).toLocaleTimeString(
        document.documentElement.lang || undefined,
        { hour: '2-digit', minute: '2-digit' },
    );
}

function frameFunction(frame: StackFrame): string | null {
    if (!frame.function) {
        return null;
    }

    return `${frame.class ? `${frame.class}::` : ''}${frame.function}()`;
}

function Frame({ frame }: { frame: StackFrame }) {
    const t = useT();
    const fn = frameFunction(frame);

    return (
        <li className="border-b border-line/60 last:border-b-0">
            <div className="flex items-start justify-between gap-3 px-4 py-2.5">
                <span className="min-w-0 font-mono text-xs break-all">
                    <span className={frame.in_app ? 'text-ink' : 'text-ink-2'}>
                        {frame.file ?? '?'}
                    </span>
                    {frame.line !== undefined && (
                        <span className="text-ink-3">:{frame.line}</span>
                    )}{' '}
                    {fn && <span className="text-info">{fn}</span>}
                </span>
                <Badge tone={frame.in_app ? 'amber' : 'neutral'}>
                    {frame.in_app
                        ? t('issue.stack.app')
                        : t('issue.stack.vendor')}
                </Badge>
            </div>
            {frame.code && frame.code.lines.length > 0 && (
                <pre className="overflow-x-auto border-t border-line/60 bg-ground font-mono text-xs leading-5">
                    {frame.code.lines.map((text, i) => {
                        const number = frame.code!.start + i;
                        const current = number === frame.line;

                        return (
                            <div
                                key={number}
                                className={cn(
                                    'flex',
                                    current && 'bg-err-bg text-err',
                                )}
                            >
                                <span
                                    className={cn(
                                        'w-14 shrink-0 pr-3 text-right select-none',
                                        current ? 'text-err' : 'text-ink-3',
                                    )}
                                >
                                    <span aria-hidden="true">
                                        {current ? '> ' : ''}
                                    </span>
                                    {number}
                                </span>
                                <span className="whitespace-pre">
                                    {current && (
                                        <span className="sr-only">
                                            {t('issue.stack.error_line')}{' '}
                                        </span>
                                    )}
                                    {text}
                                </span>
                            </div>
                        );
                    })}
                </pre>
            )}
        </li>
    );
}

function ContextValue({ value }: { value: unknown }) {
    if (value !== null && typeof value === 'object') {
        return (
            <pre className="overflow-x-auto font-mono text-xs whitespace-pre-wrap">
                {JSON.stringify(value, null, 2)}
            </pre>
        );
    }

    return (
        <span className="font-mono text-xs break-all">
            {value === null || value === undefined || value === ''
                ? '-'
                : String(value as string | number | boolean)}
        </span>
    );
}

function KeyValues({ rows }: { rows: [string, unknown][] }) {
    return (
        <dl className="grid grid-cols-[minmax(80px,auto)_minmax(0,1fr)] gap-x-3 gap-y-2 px-4 py-3.5">
            {rows.map(([key, value]) => (
                <div key={key} className="contents">
                    <dt className="text-ink-3">{key}</dt>
                    <dd className="min-w-0">
                        <ContextValue value={value} />
                    </dd>
                </div>
            ))}
        </dl>
    );
}

type ContextSection = { title: string; rows: [string, unknown][] };

function isRecord(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * One table per object: scalars become rows, nested objects their own table below (request, then request.headers).
 */
function contextSections(
    title: string,
    value: Record<string, unknown>,
    depth = 0,
): ContextSection[] {
    const rows: [string, unknown][] = [];
    const nested: ContextSection[] = [];

    for (const [key, item] of Object.entries(value)) {
        if (isRecord(item) && Object.keys(item).length > 0 && depth < 3) {
            nested.push(...contextSections(`${title}.${key}`, item, depth + 1));
        } else {
            rows.push([key, item]);
        }
    }

    return [...(rows.length > 0 ? [{ title, rows }] : []), ...nested];
}

function ContextCell({ value }: { value: unknown }) {
    const t = useT();

    if (value === '[filtered]') {
        return <Badge>{t('issue.side.filtered')}</Badge>;
    }

    if (value === null || value === undefined || value === '') {
        return <span className="text-ink-3">-</span>;
    }

    if (
        Array.isArray(value) &&
        value.every((v) => v === null || typeof v !== 'object')
    ) {
        return (
            <span className="break-all">{value.map(String).join(', ')}</span>
        );
    }

    if (typeof value === 'object') {
        return (
            <pre className="font-mono text-xs break-all whitespace-pre-wrap">
                {JSON.stringify(value, null, 2)}
            </pre>
        );
    }

    return (
        <span className="break-all">
            {String(value as string | number | boolean)}
        </span>
    );
}

function ContextTables({ context }: { context: Record<string, unknown> }) {
    const t = useT();
    const loose: [string, unknown][] = Object.entries(context).filter(
        ([, v]) => !isRecord(v),
    );
    const sections = [
        ...(loose.length > 0
            ? [{ title: t('issue.side.general'), rows: loose }]
            : []),
        ...Object.entries(context).flatMap(([key, v]) =>
            isRecord(v) ? contextSections(key, v) : [],
        ),
    ];

    return (
        <div className="flex flex-col">
            {sections.map((section) => (
                <div
                    key={section.title}
                    className="border-t border-line first:border-t-0"
                >
                    <h3 className="bg-rail px-4 py-1.5 font-mono text-[11px] font-medium tracking-wide text-ink-3 uppercase">
                        {section.title}
                    </h3>
                    <table className="w-full table-fixed text-xs">
                        <tbody>
                            {section.rows.map(([key, value]) => (
                                <tr
                                    key={key}
                                    className="border-t border-line/60 align-top"
                                >
                                    <th
                                        scope="row"
                                        className="w-2/5 px-4 py-1.5 text-left font-mono font-normal break-words text-ink-3"
                                    >
                                        {key}
                                    </th>
                                    <td className="py-1.5 pr-4 font-mono text-ink-2">
                                        <ContextCell value={value} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ))}
        </div>
    );
}

type HistoryEntry = {
    type:
        | 'created'
        | 'regressed'
        | 'escalated'
        | 'status_changed'
        | 'assigned'
        | 'labels_changed'
        | 'repo_issue_created'
        | 'returned'
        | 'note';
    actor: string | null;
    via_mcp: boolean;
    data: Record<string, unknown> | null;
    at: string;
};

const historyDot: Record<HistoryEntry['type'], string> = {
    created: 'bg-err',
    regressed: 'bg-err',
    escalated: 'bg-err',
    status_changed: 'bg-ok',
    assigned: 'bg-info',
    labels_changed: 'bg-info',
    repo_issue_created: 'bg-ink-3',
    returned: 'bg-warn',
    note: 'bg-amber',
};

/**
 * The issue's life, newest first: when it first happened, regressions and
 * escalations, who changed its status, assignee or labels, and every note.
 */
function IssueHistory({
    entries,
    candidates,
}: {
    entries: HistoryEntry[];
    candidates: Mentionable[];
}) {
    const t = useT();

    if (entries.length === 0) {
        return <p className="text-ink-3">{t('issue.history.empty')}</p>;
    }

    const text = (e: HistoryEntry): string => {
        const d = e.data ?? {};
        const status = (value: unknown) =>
            typeof value === 'string' ? t(`common.statuses.${value}`) : '';
        const str = (value: unknown) =>
            typeof value === 'string' || typeof value === 'number'
                ? String(value)
                : '';
        const names = (value: unknown) =>
            Array.isArray(value) ? value.map(String).join(', ') : '';

        switch (e.type) {
            case 'status_changed':
                return d.from
                    ? t('issue.history.status_changed', {
                          from: status(d.from),
                          to: status(d.to),
                      })
                    : t('issue.history.status_set', { to: status(d.to) });
            case 'assigned':
                return d.to
                    ? t('issue.history.assigned', { to: str(d.to) })
                    : t('issue.history.unassigned');
            case 'labels_changed':
                return [
                    names(d.added) &&
                        t('issue.history.labels_added', {
                            labels: names(d.added),
                        }),
                    names(d.removed) &&
                        t('issue.history.labels_removed', {
                            labels: names(d.removed),
                        }),
                ]
                    .filter(Boolean)
                    .join(', ');
            case 'escalated':
                return t('issue.history.escalated', {
                    burst: str(d.burst),
                    minutes: str(d.minutes ?? 5),
                });
            case 'returned':
                return t(`issue.history.returned_${str(d.reason) || 'time'}`, {
                    burst: str(d.burst),
                    minutes: str(d.minutes ?? 5),
                });
            case 'note':
                return d.status
                    ? t('issue.history.note_with_status', {
                          status: status(d.status),
                      })
                    : t('issue.history.note');
            default:
                return t(`issue.history.${e.type}`);
        }
    };

    return (
        <ol className="flex flex-col">
            {entries.map((e, i) => (
                <li key={`${e.type}-${e.at}-${i}`} className="flex gap-3">
                    <span
                        className="flex flex-col items-center"
                        aria-hidden="true"
                    >
                        <span
                            className={cn(
                                'mt-1.5 size-2 rounded-full',
                                historyDot[e.type],
                            )}
                        />
                        {i < entries.length - 1 && (
                            <span className="w-px flex-1 bg-line" />
                        )}
                    </span>
                    <div className="flex min-w-0 flex-1 flex-col gap-1 pb-4">
                        <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span>{text(e)}</span>
                            {e.actor && (
                                <span className="text-ink-3">
                                    {t('issue.history.by', { actor: e.actor })}
                                    {e.via_mcp && ' (MCP)'}
                                </span>
                            )}
                            <time
                                dateTime={e.at}
                                title={formatDateTime(e.at)}
                                className="text-xs text-ink-3"
                            >
                                {formatRelative(e.at)}
                            </time>
                        </span>
                        {e.type === 'note' &&
                            typeof e.data?.body === 'string' && (
                                <div className="rounded-md border border-line bg-rail px-3 py-2 text-[13px]">
                                    <NoteBody
                                        body={e.data.body}
                                        candidates={candidates}
                                    />
                                </div>
                            )}
                        {e.type === 'repo_issue_created' &&
                            typeof e.data?.url === 'string' && (
                                <a
                                    href={e.data.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-mono text-xs break-all text-amber hover:underline"
                                >
                                    {e.data.url}
                                </a>
                            )}
                    </div>
                </li>
            ))}
        </ol>
    );
}

const IGNORE_CHOICES = [
    'escalation',
    '1h',
    '24h',
    '7d',
    'count:10',
    'count:100',
    'count:1000',
    'forever',
] as const;

function StatusCommentDialog({
    status,
    candidates,
    onClose,
    onConfirm,
}: {
    status: 'resolved' | 'ignored' | null;
    candidates: Mentionable[];
    onClose: () => void;
    onConfirm: (
        status: 'resolved' | 'ignored',
        comment: string,
        until: string | null,
    ) => void;
}) {
    const t = useT();
    const [comment, setComment] = useState('');
    const [until, setUntil] = useState('escalation');

    return (
        <Dialog
            open={status !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setComment('');
                    onClose();
                }
            }}
        >
            <DialogContent>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();

                        if (status !== null) {
                            onConfirm(
                                status,
                                comment,
                                status === 'ignored' ? until : null,
                            );
                            setComment('');
                            setUntil('escalation');
                        }
                    }}
                    className="flex flex-col gap-4"
                >
                    <DialogHeader>
                        <DialogTitle>
                            {status === 'ignored'
                                ? t('issue.close.ignore_title')
                                : t('issue.close.resolve_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('issue.close.description')}
                        </DialogDescription>
                    </DialogHeader>
                    {status === 'ignored' && (
                        <Field
                            label={t('issue.close.until')}
                            htmlFor="ignore-until"
                        >
                            <Select
                                id="ignore-until"
                                value={until}
                                onChange={(e) => setUntil(e.target.value)}
                            >
                                {IGNORE_CHOICES.map((choice) => (
                                    <option key={choice} value={choice}>
                                        {t(
                                            `issue.close.choices.${choice.replace(':', '_')}`,
                                        )}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    )}
                    <MentionTextarea
                        id="status-comment"
                        value={comment}
                        onChange={setComment}
                        candidates={candidates}
                        required={false}
                        placeholder={t('issue.close.placeholder')}
                    />
                    <DialogFooter>
                        <Button onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" variant="primary">
                            {status === 'ignored'
                                ? t('issue.actions.ignore')
                                : t('issue.actions.resolve')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function IssueShow({
    project,
    issue,
    latest,
    occurrences,
    series,
    notes,
    members,
    agents,
    labels,
    history,
    has_repository,
}: Props) {
    const t = useT();
    const { team, auth } = usePage<{
        team: TeamShared;
        auth: { user: { id: number } | null };
    }>().props;
    const [tab, setTab] = useState<Tab>('stacktrace');
    const tabRefs = useRef<Record<Tab, HTMLButtonElement | null>>({
        stacktrace: null,
        occurrences: null,
        notes: null,
        history: null,
    });
    const frames = latest?.stacktrace ?? [];
    const appFrames = frames.filter((f) => f.in_app);
    const vendorCount = frames.length - appFrames.length;
    const [showVendor, setShowVendor] = useState(appFrames.length === 0);
    const [creatingRepoIssue, setCreatingRepoIssue] = useState(false);
    const note = useForm({ body: '' });
    const key = [project.uuid, issue.id] as [string, string];

    useProjectLive(
        project.uuid,
        ['issue.updated', 'issues.changed'],
        ['issue', 'latest', 'occurrences', 'series', 'notes'],
        { issue: issue.id },
    );

    const changeStatus = (
        status: IssueSummary['status'],
        comment = '',
        until: string | null = null,
    ) =>
        router.put(
            issueStatus(key).url,
            { status, note: comment.trim() === '' ? null : comment, until },
            { preserveScroll: true },
        );

    // Resolve and ignore ask for an optional comment, which becomes a note.
    const [closing, setClosing] = useState<'resolved' | 'ignored' | null>(null);

    const createRepoIssue = () =>
        router.post(
            repoIssue(key).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setCreatingRepoIssue(true),
                onFinish: () => setCreatingRepoIssue(false),
            },
        );

    // Everyone you could tag, but you.
    const mentionable: Mentionable[] = [
        ...members.filter((m) => m.id !== auth.user?.id),
        ...agents.map((a) => ({ ...a, agent: true })),
    ];

    const submitNote = (e: FormEvent) => {
        e.preventDefault();
        note.transform((data) => ({
            ...data,
            body: toStored(data.body, mentionable),
        }));
        note.post(storeNote(key).url, {
            preserveScroll: true,
            onSuccess: () => note.reset(),
        });
    };

    const onTabKey = (e: KeyboardEvent) => {
        const step =
            e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;

        if (step === 0) {
            return;
        }

        const next =
            TABS[(TABS.indexOf(tab) + step + TABS.length) % TABS.length];
        setTab(next);
        tabRefs.current[next]?.focus();
    };

    const total = series.reduce((a, p) => a + p.v, 0);
    const peak = series.reduce<SeriesPoint | null>(
        (best, p) => (best === null || p.v > best.v ? p : best),
        null,
    );
    const seriesSummary =
        peak && peak.v > 0
            ? t(
                  'issue.series.summary',
                  {
                      count: formatNumber(total),
                      peak: formatNumber(peak.v),
                      time: hourLabel(peak.t),
                  },
                  total,
              )
            : t('issue.series.empty');

    const visibleFrames = showVendor ? frames : appFrames;
    const tabCounts: Record<Tab, number | null> = {
        stacktrace: null,
        occurrences: occurrences.length,
        notes: notes.length,
        history: null,
    };

    return (
        <>
            <Head
                title={`${issue.reference ? `${issue.reference} ` : ''}${issue.title} · ${project.name}`}
            />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    {
                        label: t('common.nav.issues'),
                        href: issuesIndex(project.uuid).url,
                    },
                    { label: issue.reference ?? issue.title, mono: true },
                ]}
            />
            <StatusCommentDialog
                status={closing}
                candidates={mentionable}
                onClose={() => setClosing(null)}
                onConfirm={(status, comment, until) => {
                    changeStatus(status, toStored(comment, mentionable), until);
                    setClosing(null);
                }}
            />
            <PageBody wide>
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-[1_1_520px] flex-col gap-2">
                        <div className="flex flex-wrap gap-2">
                            <Badge tone={statusTone[issue.status]}>
                                {t(`common.statuses.${issue.status}`)}
                            </Badge>
                            {issue.escalating && (
                                <Badge tone="err">
                                    {t('common.flags.escalating')}
                                </Badge>
                            )}
                            {issue.returned && (
                                <Badge tone="warn">
                                    {t('common.flags.returned')}
                                </Badge>
                            )}
                            {issue.ignore_until && (
                                <span className="text-xs text-ink-3">
                                    {issue.ignore_until.at
                                        ? t('issue.ignored_until.at', {
                                              time: formatDateTime(
                                                  issue.ignore_until.at,
                                              ),
                                          })
                                        : issue.ignore_until
                                                .occurrences_left !== undefined
                                          ? t('issue.ignored_until.count', {
                                                count: String(
                                                    issue.ignore_until
                                                        .occurrences_left,
                                                ),
                                            })
                                          : t('issue.ignored_until.escalation')}
                                </span>
                            )}
                            <Badge>{t(`common.sources.${issue.source}`)}</Badge>
                            {latest?.environment && (
                                <Badge>{latest.environment}</Badge>
                            )}
                            {issue.regressed && (
                                <Badge tone="err">
                                    {t('common.flags.regression')}
                                </Badge>
                            )}
                            {!issue.regressed && issue.new && (
                                <Badge tone="info">
                                    {t('common.flags.new')}
                                </Badge>
                            )}
                        </div>
                        <h1 className="font-mono text-lg font-medium break-all">
                            {issue.reference && (
                                <CopyText
                                    text={issue.reference}
                                    className="mr-2 text-ink-3"
                                />
                            )}
                            {issue.title}
                        </h1>
                        <p className="text-sm text-ink-2">{issue.message}</p>
                        {issue.location && (
                            <span className="font-mono text-xs break-all text-ink-3">
                                {issue.location}
                            </span>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <label className="sr-only" htmlFor="issue-assignee">
                            {t('issue.actions.assignee')}
                        </label>
                        <Select
                            id="issue-assignee"
                            value={issue.assignee?.id ?? ''}
                            onChange={(e) =>
                                router.put(
                                    assign(key).url,
                                    { user_id: e.target.value || null },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <option value="">{t('common.unassigned')}</option>
                            {members.map((member) => (
                                <option key={member.id} value={member.id}>
                                    {member.name}
                                </option>
                            ))}
                        </Select>
                        {issue.repo_issue_url ? (
                            <a
                                href={issue.repo_issue_url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex h-8 items-center gap-1.5 rounded-md border border-line-strong bg-panel-2 px-3 font-medium text-ink hover:bg-line"
                            >
                                <CircleDot
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                {t('issue.actions.open_repo_issue')}
                            </a>
                        ) : (
                            has_repository && (
                                <Button
                                    onClick={createRepoIssue}
                                    disabled={creatingRepoIssue}
                                >
                                    <CircleDot
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {t('issue.actions.create_repo_issue')}
                                </Button>
                            )
                        )}
                        {issue.status === 'open' ||
                        issue.status === 'in_progress' ? (
                            <>
                                {issue.status === 'open' ? (
                                    <Button
                                        onClick={() =>
                                            changeStatus('in_progress')
                                        }
                                    >
                                        {t('issue.actions.start')}
                                    </Button>
                                ) : (
                                    <Button
                                        onClick={() => changeStatus('open')}
                                    >
                                        {t('issue.actions.stop')}
                                    </Button>
                                )}
                                <Button onClick={() => setClosing('ignored')}>
                                    {t('issue.actions.ignore')}
                                </Button>
                                <Button
                                    variant="primary"
                                    onClick={() => setClosing('resolved')}
                                >
                                    {t('issue.actions.resolve')}
                                </Button>
                            </>
                        ) : (
                            <Button onClick={() => changeStatus('open')}>
                                {t('issue.actions.reopen')}
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3">
                    {[
                        [
                            t('issue.stats.occurrences'),
                            <span className="font-mono text-lg">
                                {formatNumber(issue.occurrences)}
                            </span>,
                        ],
                        [
                            t('issue.stats.first_seen'),
                            <span title={formatDateTime(issue.first_seen_at)}>
                                {formatRelative(issue.first_seen_at)}
                            </span>,
                        ],
                        [
                            t('issue.stats.last_seen'),
                            <span title={formatDateTime(issue.last_seen_at)}>
                                {formatRelative(issue.last_seen_at)}
                            </span>,
                        ],
                        [
                            t('issue.stats.releases'),
                            <span className="font-mono">
                                {issue.last_release ?? '-'}
                                {issue.releases > 1 && (
                                    <span className="text-ink-3">
                                        {' '}
                                        {t('issue.stats.more_releases', {
                                            count: issue.releases - 1,
                                        })}
                                    </span>
                                )}
                            </span>,
                        ],
                        [
                            t('issue.stats.hosts'),
                            <span className="font-mono break-all">
                                {issue.hosts ?? '-'}
                            </span>,
                        ],
                    ].map(([label, value], i) => (
                        <div
                            key={i}
                            className="flex flex-col gap-1 rounded-lg border border-line bg-panel px-3.5 py-3"
                        >
                            <Eyebrow>{label}</Eyebrow>
                            <span className="text-sm">{value}</span>
                        </div>
                    ))}
                </div>

                <Card
                    aria-labelledby="issue-series"
                    className="flex flex-col gap-2 px-4 py-3.5"
                >
                    <div className="flex flex-wrap justify-between gap-2">
                        <h2
                            id="issue-series"
                            className="text-[13px] font-semibold"
                        >
                            {t('issue.series.title')}
                        </h2>
                        {issue.regressed_at && (
                            <span className="text-xs text-ink-3">
                                {t('issue.series.regressed_at', {
                                    time: formatDateTime(issue.regressed_at),
                                })}
                            </span>
                        )}
                    </div>
                    <div>
                        <TimeBars
                            series={series}
                            period="24h"
                            label={seriesSummary}
                            height={96}
                            color="var(--lj-line)"
                            highlightNonZero="var(--lj-err)"
                            formatValue={formatNumber}
                        />
                    </div>
                </Card>

                <div className="flex flex-wrap items-start gap-4">
                    <Card className="min-w-0 flex-[999_1_560px]">
                        <div
                            role="tablist"
                            aria-label={t('issue.tabs.label')}
                            className="flex gap-4 overflow-x-auto border-b border-line px-4"
                            onKeyDown={onTabKey}
                        >
                            {TABS.map((name) => (
                                <button
                                    key={name}
                                    ref={(el) => {
                                        tabRefs.current[name] = el;
                                    }}
                                    type="button"
                                    role="tab"
                                    id={`tab-${name}`}
                                    aria-selected={tab === name}
                                    aria-controls={`panel-${name}`}
                                    tabIndex={tab === name ? 0 : -1}
                                    onClick={() => setTab(name)}
                                    className="h-10 cursor-pointer border-b-2 border-transparent px-1 whitespace-nowrap text-ink-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-amber aria-selected:border-amber aria-selected:font-medium aria-selected:text-ink"
                                >
                                    {t(`issue.tabs.${name}`)}
                                    {tabCounts[name] !== null && (
                                        <span className="ml-1.5 font-mono text-ink-3">
                                            {formatNumber(tabCounts[name]!)}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>

                        <div
                            role="tabpanel"
                            id="panel-stacktrace"
                            aria-labelledby="tab-stacktrace"
                            hidden={tab !== 'stacktrace'}
                        >
                            {frames.length === 0 ? (
                                <EmptyState title={t('issue.stack.empty')} />
                            ) : (
                                <>
                                    <ol>
                                        {visibleFrames.map((frame, i) => (
                                            <Frame key={i} frame={frame} />
                                        ))}
                                    </ol>
                                    {vendorCount > 0 &&
                                        appFrames.length > 0 && (
                                            <button
                                                type="button"
                                                aria-expanded={showVendor}
                                                onClick={() =>
                                                    setShowVendor((v) => !v)
                                                }
                                                className="w-full cursor-pointer border-t border-line px-4 py-3 text-left text-ink-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-amber"
                                            >
                                                {showVendor
                                                    ? t(
                                                          'issue.stack.hide_vendor',
                                                          {},
                                                          vendorCount,
                                                      )
                                                    : t(
                                                          'issue.stack.show_vendor',
                                                          {},
                                                          vendorCount,
                                                      )}
                                            </button>
                                        )}
                                </>
                            )}
                        </div>

                        <div
                            role="tabpanel"
                            id="panel-occurrences"
                            aria-labelledby="tab-occurrences"
                            hidden={tab !== 'occurrences'}
                            className="overflow-x-auto"
                        >
                            {occurrences.length === 0 ? (
                                <EmptyState
                                    title={t('issue.occurrences.empty')}
                                />
                            ) : (
                                <table className={tableClass}>
                                    <thead>
                                        <tr>
                                            <th className={thClass}>
                                                {t('issue.occurrences.time')}
                                            </th>
                                            <th className={thClass}>
                                                {t('issue.occurrences.request')}
                                            </th>
                                            <th className={thClass}>
                                                {t('issue.occurrences.user')}
                                            </th>
                                            <th className={thClass}>
                                                {t('issue.fields.environment')}
                                            </th>
                                            <th className={thClass}>
                                                {t('issue.fields.release')}
                                            </th>
                                            <th className={thClass}>
                                                {t('issue.fields.host')}
                                            </th>
                                            <th className={thClass}>
                                                {t('issue.fields.trace_id')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {occurrences.map((o) => (
                                            <tr key={o.id}>
                                                <td
                                                    className={`${tdClass} font-mono text-xs whitespace-nowrap`}
                                                >
                                                    <time
                                                        dateTime={o.occurred_at}
                                                        title={formatDateTime(
                                                            o.occurred_at,
                                                        )}
                                                    >
                                                        {new Date(
                                                            o.occurred_at,
                                                        ).toLocaleDateString(
                                                            document
                                                                .documentElement
                                                                .lang ||
                                                                undefined,
                                                        )}{' '}
                                                        {formatTime(
                                                            o.occurred_at,
                                                            true,
                                                        )}
                                                    </time>
                                                </td>
                                                <td
                                                    className={`${tdClass} font-mono text-xs`}
                                                >
                                                    {o.request
                                                        ? `${o.request.method ?? ''} ${o.request.route ?? ''}`.trim() ||
                                                          '-'
                                                        : '-'}
                                                </td>
                                                <td
                                                    className={`${tdClass} font-mono text-xs`}
                                                >
                                                    {o.user_id ?? '-'}
                                                </td>
                                                <td
                                                    className={`${tdClass} font-mono text-xs`}
                                                >
                                                    {o.environment ?? '-'}
                                                </td>
                                                <td
                                                    className={`${tdClass} font-mono text-xs`}
                                                >
                                                    {o.release ?? '-'}
                                                </td>
                                                <td
                                                    className={`${tdClass} font-mono text-xs`}
                                                >
                                                    {o.host ?? '-'}
                                                </td>
                                                <td
                                                    className={`${tdClass} font-mono text-xs`}
                                                >
                                                    {o.trace_id ? (
                                                        <Link
                                                            href={
                                                                traceShow([
                                                                    project.uuid,
                                                                    o.trace_id,
                                                                ]).url
                                                            }
                                                            className="text-amber hover:underline"
                                                        >
                                                            {o.trace_id}
                                                        </Link>
                                                    ) : (
                                                        '-'
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>

                        <div
                            role="tabpanel"
                            id="panel-history"
                            aria-labelledby="tab-history"
                            hidden={tab !== 'history'}
                            className="p-4"
                        >
                            <IssueHistory
                                entries={history}
                                candidates={mentionable}
                            />
                        </div>

                        <div
                            role="tabpanel"
                            id="panel-notes"
                            aria-labelledby="tab-notes"
                            hidden={tab !== 'notes'}
                            className="flex flex-col gap-3.5 p-4"
                        >
                            {notes.length === 0 && (
                                <p className="text-ink-3">
                                    {t('issue.notes.empty')}
                                </p>
                            )}
                            {notes.map((n) => (
                                <article
                                    key={n.id}
                                    className="flex flex-col gap-1.5 rounded-lg border border-line bg-rail px-3.5 py-3"
                                >
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-ink-3">
                                        {n.status && (
                                            <Badge tone={statusTone[n.status]}>
                                                {t(
                                                    `common.statuses.${n.status}`,
                                                )}
                                            </Badge>
                                        )}
                                        {n.via_mcp !== null && (
                                            <Badge tone="info">MCP</Badge>
                                        )}
                                        <span>
                                            {n.via_mcp !== null
                                                ? t('issue.notes.via_mcp', {
                                                      token: n.via_mcp,
                                                  })
                                                : (n.author ??
                                                  t(
                                                      'issue.notes.unknown_author',
                                                  ))}
                                            {', '}
                                            <time
                                                dateTime={
                                                    n.created_at ?? undefined
                                                }
                                                title={formatDateTime(
                                                    n.created_at,
                                                )}
                                            >
                                                {formatRelative(n.created_at)}
                                            </time>
                                        </span>
                                    </div>
                                    <NoteBody
                                        body={n.body}
                                        candidates={mentionable}
                                    />
                                </article>
                            ))}
                            <form
                                onSubmit={submitNote}
                                className="flex flex-col gap-2"
                            >
                                <Field
                                    label={t('issue.notes.new')}
                                    htmlFor="note-body"
                                    error={note.errors.body}
                                >
                                    <MentionTextarea
                                        id="note-body"
                                        value={note.data.body}
                                        onChange={(value) =>
                                            note.setData('body', value)
                                        }
                                        candidates={mentionable}
                                        placeholder={t(
                                            'issue.notes.placeholder',
                                        )}
                                        invalid={!!note.errors.body}
                                    />
                                </Field>
                                <div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            note.processing ||
                                            note.data.body.trim() === ''
                                        }
                                    >
                                        {t('issue.notes.save')}
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </Card>

                    <aside
                        className="flex min-w-0 flex-[1_1_320px] flex-col gap-4"
                        aria-label={t('issue.side.label')}
                    >
                        <Card aria-labelledby="side-context">
                            <CardHeader
                                id="side-context"
                                title={t('issue.side.context')}
                            />
                            {latest &&
                            Object.keys(latest.context).length > 0 ? (
                                <ContextTables context={latest.context} />
                            ) : (
                                <p className="px-4 py-3.5 text-ink-3">
                                    {t('issue.side.no_context')}
                                </p>
                            )}
                        </Card>
                        <Card aria-labelledby="side-labels">
                            <CardHeader
                                id="side-labels"
                                title={t('issue.labels.title')}
                            />
                            <LabelEditor
                                issueKey={key}
                                labels={issue.labels}
                                available={labels}
                            />
                        </Card>
                        <Card aria-labelledby="side-environment">
                            <CardHeader
                                id="side-environment"
                                title={t('issue.side.environment')}
                            />
                            <KeyValues
                                rows={[
                                    [
                                        t('issue.fields.environment'),
                                        latest?.environment,
                                    ],
                                    [
                                        t('issue.fields.release'),
                                        latest?.release,
                                    ],
                                    [t('issue.fields.host'), latest?.host],
                                    [
                                        t('issue.fields.trace_id'),
                                        latest?.trace_id,
                                    ],
                                ]}
                            />
                        </Card>
                        {issue.repo_issue_url && (
                            <Card aria-labelledby="side-github">
                                <CardHeader
                                    id="side-github"
                                    title={t('issue.side.github')}
                                />
                                <div className="px-4 py-3.5">
                                    <a
                                        href={issue.repo_issue_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="font-mono text-xs break-all text-amber hover:underline"
                                    >
                                        {issue.repo_issue_url.replace(
                                            /^https?:\/\/(www\.)?github\.com\//,
                                            '',
                                        )}
                                    </a>
                                </div>
                            </Card>
                        )}
                    </aside>
                </div>
            </PageBody>
        </>
    );
}
