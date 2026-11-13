import { Head, Link, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, CircleDashed, Search } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConnectorIcon } from '@/components/lj/connectors/connector-icon';
import { GitHubConnectDialog } from '@/components/lj/connectors/github/connect';
import { MobilePayConnectDialog } from '@/components/lj/connectors/mobilepay/connect';
import { MosparoConnectDialog } from '@/components/lj/connectors/mosparo/connect';
import { PackagistConnectDialog } from '@/components/lj/connectors/packagist/connect';
import { UmamiConnectDialog } from '@/components/lj/connectors/umami/connect';
import { WithRelative } from '@/components/lj/relative';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    Card,
    EmptyState,
    Input,
    LinkButton,
    PageTitle,
} from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { show as projectShow } from '@/routes/projects';
import type { ProjectHeader, TeamShared } from '@/types/lumberjack';

/** One of the project's connections to a service, by name, with how its checks went. */
type ConnectionRow = {
    uuid: string;
    name: string;
    url: string;
    environment: string;
    last_ok_at: string | null;
    last_checked_at: string | null;
    last_error: string | null;
};

/** A service in the catalog, with what the project has connected of it. */
type ConnectorRow = {
    key: string;
    label: string;
    description: string;
    tags: string[];
    url: string;
    connections: ConnectionRow[];
};

/** How many connections a card lists; the rest are in the dialog. */
/** Above this many rows the dialog gets a search. */
const SEARCH_FROM = 8;

type Props = {
    project: ProjectHeader;
    /** Connected first, then the rest, each by name. */
    connectors: ConnectorRow[];
    tags: { key: string; label: string }[];
};

/** Each connector's dialog for connecting it, by its key in the catalog. */
const CONNECT_DIALOGS: Record<
    string,
    (props: { project: string; onClose: () => void }) => ReactNode
> = {
    umami: UmamiConnectDialog,
    mosparo: MosparoConnectDialog,
    github: GitHubConnectDialog,
    mobilepay: MobilePayConnectDialog,
    packagist: PackagistConnectDialog,
};

const ISSUES_URL = 'https://github.com/ExeQue/lumberjack/issues';
const PULLS_URL = 'https://github.com/ExeQue/lumberjack/pulls';

type Filters = { q: string; tags: string[] };

/** The search and the tags, read from the address so a shared link shows the same list. */
function readFilters(): Filters {
    const params = new URLSearchParams(window.location.search);

    return {
        q: params.get('q') ?? '',
        tags: (params.get('tags') ?? '').split(',').filter(Boolean),
    };
}

/** Kept in the query; Inertia keeps the page in history.state, so only the address changes. */
function writeFilters({ q, tags }: Filters) {
    const params = new URLSearchParams();

    if (q !== '') {
        params.set('q', q);
    }

    if (tags.length > 0) {
        params.set('tags', tags.join(','));
    }

    const query = params.toString();
    window.history.replaceState(
        window.history.state,
        '',
        window.location.pathname + (query === '' ? '' : `?${query}`),
    );
}

export default function ConnectorsIndex({ project, connectors, tags }: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [filters, setFiltersState] = useState<Filters>(readFilters);
    const [connecting, setConnecting] = useState<string | null>(null);
    const Connect = connecting === null ? null : CONNECT_DIALOGS[connecting];

    const setFilters = (next: Filters) => {
        setFiltersState(next);
        writeFilters(next);
    };
    const toggleTag = (tag: string) =>
        setFilters({
            ...filters,
            tags: filters.tags.includes(tag)
                ? filters.tags.filter((key) => key !== tag)
                : [...filters.tags, tag],
        });

    const tagLabel = (key: string) =>
        tags.find((tag) => tag.key === key)?.label ?? key;
    const needle = filters.q.trim().toLocaleLowerCase();
    // Any of the picked tags, and the search in the name, the description or a tag.
    const shown = connectors.filter(
        (connector) =>
            (filters.tags.length === 0 ||
                connector.tags.some((tag) => filters.tags.includes(tag))) &&
            [
                connector.label,
                connector.description,
                ...connector.tags.map(tagLabel),
            ].some((text) => text.toLocaleLowerCase().includes(needle)),
    );

    return (
        <>
            <Head title={`${t('connectors.index.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('connectors.index.title') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('connectors.index.title')}
                    description={t('connectors.index.description')}
                />

                <div className="flex flex-col gap-3">
                    <div className="relative max-w-md">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-ink-3"
                            aria-hidden="true"
                        />
                        <Input
                            type="search"
                            aria-label={t('connectors.index.search')}
                            placeholder={t(
                                'connectors.index.search_placeholder',
                            )}
                            value={filters.q}
                            onChange={(e) =>
                                setFilters({ ...filters, q: e.target.value })
                            }
                            className="w-full pl-8"
                            autoComplete="off"
                        />
                    </div>
                    <div
                        role="group"
                        aria-label={t('connectors.index.tags')}
                        className="flex flex-wrap gap-1.5"
                    >
                        {tags.map((tag) => (
                            <button
                                key={tag.key}
                                type="button"
                                aria-pressed={filters.tags.includes(tag.key)}
                                onClick={() => toggleTag(tag.key)}
                                className="h-7 cursor-pointer rounded-full border border-line-strong bg-panel px-3 text-xs text-ink-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber aria-pressed:border-amber aria-pressed:bg-amber/10 aria-pressed:text-ink"
                            >
                                {tag.label}
                            </button>
                        ))}
                    </div>
                </div>

                <p role="status" className="sr-only">
                    {t(
                        'connectors.index.results',
                        { count: shown.length },
                        shown.length,
                    )}
                </p>

                {shown.length === 0 ? (
                    <Card>
                        <EmptyState title={t('connectors.index.empty_title')}>
                            <Button
                                onClick={() => setFilters({ q: '', tags: [] })}
                            >
                                {t('connectors.index.clear')}
                            </Button>
                        </EmptyState>
                    </Card>
                ) : (
                    <ul className="grid grid-cols-[repeat(auto-fill,minmax(min(100%,300px),1fr))] gap-3">
                        {shown.map((connector) => (
                            <ConnectorCard
                                key={connector.key}
                                connector={connector}
                                tagLabel={tagLabel}
                                canConnect={
                                    project.can_administer &&
                                    connector.key in CONNECT_DIALOGS
                                }
                                onConnect={() => setConnecting(connector.key)}
                            />
                        ))}
                    </ul>
                )}

                <p className="flex flex-wrap gap-x-3 gap-y-1 text-[13px] text-ink-2">
                    {t('connectors.index.missing')}
                    <a
                        href={ISSUES_URL}
                        target="_blank"
                        rel="noreferrer"
                        className="text-ink underline underline-offset-2 hover:text-amber"
                    >
                        {t('connectors.index.missing_issue')}
                    </a>
                    <a
                        href={PULLS_URL}
                        target="_blank"
                        rel="noreferrer"
                        className="text-ink underline underline-offset-2 hover:text-amber"
                    >
                        {t('connectors.index.missing_pull')}
                    </a>
                </p>

                {Connect && (
                    <Connect
                        project={project.uuid}
                        onClose={() => setConnecting(null)}
                    />
                )}
            </PageBody>
        </>
    );
}

function ConnectorCard({
    connector,
    tagLabel,
    canConnect,
    onConnect,
}: {
    connector: ConnectorRow;
    tagLabel: (key: string) => string;
    canConnect: boolean;
    onConnect: () => void;
}) {
    const t = useT();
    const [listing, setListing] = useState(false);
    const connected = connector.connections.length > 0;
    const failing = connector.connections.filter(
        (connection) => connection.last_error !== null,
    ).length;
    const titleId = `connector-${connector.key}`;

    return (
        <li className="flex">
            <Card
                aria-labelledby={titleId}
                className="flex flex-1 flex-col gap-3 p-4"
            >
                <div className="flex items-start gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-md border border-line bg-panel-2 text-ink-2">
                        <ConnectorIcon
                            connector={connector.key}
                            brand
                            className="size-4"
                        />
                    </span>
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <span className="flex flex-wrap items-center gap-2">
                            <h2 id={titleId} className="text-sm font-semibold">
                                {connector.label}
                            </h2>
                            <Badge tone={connected ? 'ok' : 'neutral'}>
                                {t(
                                    connected
                                        ? 'connectors.index.connected'
                                        : 'connectors.index.not_connected',
                                )}
                            </Badge>
                        </span>
                        <p className="text-xs text-ink-2">
                            {connector.description}
                        </p>
                    </div>
                </div>

                <ul className="flex flex-wrap gap-1">
                    {connector.tags.map((tag) => (
                        <li key={tag}>
                            <Badge>{tagLabel(tag)}</Badge>
                        </li>
                    ))}
                </ul>

                {connected && (
                    <div className="text-xs text-ink-2">
                        <button
                            type="button"
                            onClick={() => setListing(true)}
                            className="cursor-pointer underline-offset-2 hover:text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber"
                        >
                            {t(
                                'connectors.index.connections',
                                { count: connector.connections.length },
                                connector.connections.length,
                            )}
                        </button>
                        {failing > 0 && (
                            <span className="text-err">
                                {' · '}
                                {t('connectors.index.failing', {
                                    count: failing,
                                })}
                            </span>
                        )}
                    </div>
                )}

                {(connected || canConnect) && (
                    <div className="mt-auto flex flex-wrap gap-2 pt-1">
                        {connected && (
                            <LinkButton
                                href={connector.url}
                                variant={canConnect ? 'primary' : 'default'}
                                aria-label={t('connectors.index.open_label', {
                                    connector: connector.label,
                                })}
                            >
                                {t('connectors.index.open')}
                            </LinkButton>
                        )}
                        {canConnect && (
                            <Button
                                variant={connected ? 'default' : 'primary'}
                                onClick={onConnect}
                                aria-label={t(
                                    connected
                                        ? 'connectors.index.connect_another_label'
                                        : 'connectors.index.connect_label',
                                    { connector: connector.label },
                                )}
                            >
                                {t(
                                    connected
                                        ? 'connectors.index.connect_another'
                                        : 'connectors.index.connect',
                                )}
                            </Button>
                        )}
                    </div>
                )}
            </Card>
            {listing && (
                <ConnectionsDialog
                    connector={connector}
                    canConnect={canConnect}
                    onConnect={() => {
                        setListing(false);
                        onConnect();
                    }}
                    onClose={() => setListing(false)}
                />
            )}
        </li>
    );
}

/** Failing on an error, OK once a check went through, else not checked yet. */
function statusOf(connection: ConnectionRow): 'failing' | 'ok' | 'unchecked' {
    if (connection.last_error !== null) {
        return 'failing';
    }

    return connection.last_ok_at === null ? 'unchecked' : 'ok';
}

const STATUS_ICONS = {
    ok: CircleCheck,
    failing: CircleAlert,
    unchecked: CircleDashed,
};

const STATUS_CLASSES = {
    ok: 'text-ok',
    failing: 'text-err',
    unchecked: 'text-ink-3',
};

/** The status in a word beside its icon, so it never rests on color alone. */
function ConnectionStatus({ connection }: { connection: ConnectionRow }) {
    const t = useT();
    const status = statusOf(connection);
    const Icon = STATUS_ICONS[status];

    return (
        <span
            className={`inline-flex shrink-0 items-center gap-1 ${STATUS_CLASSES[status]}`}
        >
            <Icon className="size-3.5" aria-hidden="true" />
            {t(`connectors.index.statuses.${status}`)}
        </span>
    );
}

/** Every connection of a connector, with its status, searchable once the list is long. */
function ConnectionsDialog({
    connector,
    canConnect,
    onConnect,
    onClose,
}: {
    connector: ConnectorRow;
    canConnect: boolean;
    onConnect: () => void;
    onClose: () => void;
}) {
    const t = useT();
    const [q, setQ] = useState('');
    const needle = q.trim().toLocaleLowerCase();
    const shown = connector.connections.filter((connection) =>
        connection.name.toLocaleLowerCase().includes(needle),
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('connectors.index.dialog.title', {
                            connector: connector.label,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            'connectors.index.connections',
                            { count: connector.connections.length },
                            connector.connections.length,
                        )}
                    </DialogDescription>
                </DialogHeader>

                {connector.connections.length > SEARCH_FROM && (
                    <div className="relative">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-ink-3"
                            aria-hidden="true"
                        />
                        <Input
                            type="search"
                            aria-label={t('connectors.index.dialog.search')}
                            placeholder={t(
                                'connectors.index.dialog.search_placeholder',
                            )}
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            className="w-full pl-8"
                            autoComplete="off"
                        />
                    </div>
                )}

                {shown.length === 0 ? (
                    <p role="status" className="text-sm text-ink-2">
                        {t('connectors.index.dialog.empty')}
                    </p>
                ) : (
                    <ul className="-mx-1 flex max-h-[60vh] flex-col overflow-y-auto">
                        {shown.map((connection) => (
                            <li
                                key={connection.uuid}
                                className="flex flex-col gap-0.5 border-b border-line/60 px-1 py-2 text-sm last:border-b-0"
                            >
                                <span className="flex items-center justify-between gap-3">
                                    <Link
                                        href={connection.url}
                                        className="truncate text-ink underline-offset-2 hover:underline"
                                    >
                                        {connection.name}
                                    </Link>
                                    <span className="text-xs">
                                        <ConnectionStatus
                                            connection={connection}
                                        />
                                    </span>
                                </span>
                                <ConnectionDetail connection={connection} />
                            </li>
                        ))}
                    </ul>
                )}

                {canConnect && (
                    <DialogFooter>
                        <Button
                            onClick={onConnect}
                            aria-label={t(
                                'connectors.index.connect_another_label',
                                { connector: connector.label },
                            )}
                        >
                            {t('connectors.index.connect_another')}
                        </Button>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** Under a connection in the dialog: its error, or when it was last checked. */
function ConnectionDetail({ connection }: { connection: ConnectionRow }) {
    const t = useT();
    const status = statusOf(connection);

    if (status === 'failing') {
        return (
            <span className="text-xs wrap-break-word text-ink-2">
                {connection.last_error}
            </span>
        );
    }

    if (status === 'unchecked') {
        return null;
    }

    return (
        <span className="text-xs text-ink-3">
            <WithRelative
                iso={connection.last_checked_at ?? connection.last_ok_at}
            >
                {(time) => t('connectors.index.dialog.checked', { time })}
            </WithRelative>
        </span>
    );
}
