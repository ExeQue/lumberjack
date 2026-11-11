import { Head, Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConnectorIcon } from '@/components/lj/connectors/connector-icon';
import { GitHubConnectDialog } from '@/components/lj/connectors/github/connect';
import { MobilePayConnectDialog } from '@/components/lj/connectors/mobilepay/connect';
import { MosparoConnectDialog } from '@/components/lj/connectors/mosparo/connect';
import { UmamiConnectDialog } from '@/components/lj/connectors/umami/connect';
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
import { PageBody } from '@/layouts/lumberjack-layout';
import { useT } from '@/lib/i18n';
import { show as projectShow } from '@/routes/projects';
import type { ProjectHeader, TeamShared } from '@/types/lumberjack';

/** A service in the catalog, with what the project has connected of it. */
type ConnectorRow = {
    key: string;
    label: string;
    description: string;
    tags: string[];
    url: string;
    connections: { uuid: string; name: string; url: string }[];
};

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
    const connected = connector.connections.length > 0;
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
                        {t(
                            'connectors.index.connections',
                            { count: connector.connections.length },
                            connector.connections.length,
                        )}
                        <ul className="mt-1 flex flex-col gap-0.5">
                            {connector.connections.map((connection) => (
                                <li key={connection.uuid} className="truncate">
                                    <Link
                                        href={connection.url}
                                        className="text-ink underline-offset-2 hover:underline"
                                    >
                                        {connection.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
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
        </li>
    );
}
