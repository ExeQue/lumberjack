import { Form, Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { timezones } from '@/components/lj/screen/lib';
import { Relative } from '@/components/lj/relative';
import { TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    Mono,
    PageTitle,
    Select,
    tableClass,
    tdClass,
    thClass,
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
import { formatDateTime } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    destroy as sourceDestroy,
    fetch as sourceFetch,
    store as sourceStore,
    update as sourceUpdate,
} from '@/routes/data-sources';
import {
    destroy as connectionDestroy,
    rotate as connectionRotate,
    update as connectionUpdate,
    store as connectionStore,
} from '@/routes/data-source-connections';
import {
    settings as projectSettings,
    show as projectShow,
} from '@/routes/projects';
import type { ProjectHeader, TeamShared } from '@/types/lumberjack';

/** A source as the page gets it: its headers by name only, their values never leave the server. */
type DataSourceRow = {
    uuid: string;
    name: string;
    url: string;
    format: 'json' | 'prometheus' | 'ical';
    header_names: string[];
    interval_minutes: number;
    timeout_s: number;
    last_fetched_at: string | null;
    last_ok_at: string | null;
    last_status_code: number | null;
    last_error: string | null;
    preview: string | null;
};

/** A source a connected app offers: read-only here, it comes and goes with the app. */
type ConnectedRow = {
    uuid: string;
    name: string;
    key: string;
    environment: string | null;
    shape: 'number' | 'series' | 'list' | 'table';
    status: 'connected' | 'failing' | 'archived';
    last_ok_at: string | null;
    last_error: string | null;
};

type ConnectionRow = {
    uuid: string;
    environment: string;
    url: string;
    timezone: string;
    status: 'connected' | 'waiting' | 'failing';
    last_ok_at: string | null;
    last_error: string | null;
};

type Props = {
    project: ProjectHeader;
    sources: DataSourceRow[];
    connected: ConnectedRow[];
    connections: ConnectionRow[];
};

function When({ iso }: { iso: string | null }) {
    const t = useT();

    return iso ? (
        <time dateTime={iso} title={formatDateTime(iso)}>
            <Relative iso={iso} />
        </time>
    ) : (
        <span className="text-ink-3">{t('data_sources.never')}</span>
    );
}

const reportedTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const browserTimezone =
    reportedTimezone === 'Etc/Unknown' ? 'UTC' : reportedTimezone;

export default function DataSourcesIndex({
    project,
    sources,
    connected,
    connections,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [dialog, setDialog] = useState<DataSourceRow | 'new' | null>(null);

    return (
        <>
            <Head title={`${t('data_sources.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    ...(project.can_administer
                        ? [
                              {
                                  label: t('common.nav.project_settings'),
                                  href: projectSettings(project.uuid).url,
                              },
                          ]
                        : []),
                    { label: t('data_sources.title') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('data_sources.title')}
                    description={t(
                        'data_sources.description',
                        {},
                        sources.length,
                    )}
                    actions={
                        project.can_administer && (
                            <Button
                                variant="primary"
                                onClick={() => setDialog('new')}
                            >
                                {t('data_sources.add')}
                            </Button>
                        )
                    }
                />

                <ConnectedSources
                    project={project}
                    sources={connected}
                    connections={connections}
                />

                <Card aria-labelledby="data-sources-title">
                    <CardHeader
                        id="data-sources-title"
                        title={t('data_sources.list_title')}
                        description={t('data_sources.list_hint')}
                    />
                    {sources.length === 0 ? (
                        <EmptyState title={t('data_sources.empty_title')}>
                            {t('data_sources.empty_body')}
                        </EmptyState>
                    ) : (
                        <div className="relative overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('data_sources.columns.name')}
                                        </th>
                                        <th className={thClass}>
                                            {t('data_sources.columns.status')}
                                        </th>
                                        <th className={thClass}>
                                            {t(
                                                'data_sources.columns.last_fetch',
                                            )}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'hidden md:table-cell',
                                            )}
                                        >
                                            {t('data_sources.columns.every')}
                                        </th>
                                        {project.can_administer && (
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'hidden md:table-cell',
                                                )}
                                            >
                                                <span className="sr-only">
                                                    {t(
                                                        'data_sources.columns.actions',
                                                    )}
                                                </span>
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {sources.map((source) => (
                                        <SourceRow
                                            key={source.uuid}
                                            project={project}
                                            source={source}
                                            onEdit={() => setDialog(source)}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                {dialog !== null && (
                    <SourceDialog
                        project={project.uuid}
                        source={dialog === 'new' ? undefined : dialog}
                        onClose={() => setDialog(null)}
                    />
                )}
            </PageBody>
        </>
    );
}

const CONNECTED_TONES = {
    connected: 'ok',
    failing: 'err',
    waiting: undefined,
    archived: undefined,
} as const;

function ConnectedSources({
    project,
    sources,
    connections,
}: {
    project: ProjectHeader;
    sources: ConnectedRow[];
    connections: ConnectionRow[];
}) {
    const t = useT();
    const [connecting, setConnecting] = useState(false);
    const [changingUrl, setChangingUrl] = useState<ConnectionRow | null>(null);

    return (
        <Card aria-labelledby="connected-sources-title" className="mb-6">
            <CardHeader
                id="connected-sources-title"
                title={t('data_sources.connected.title')}
                description={t('data_sources.connected.hint')}
                aside={
                    project.can_administer && (
                        <Button
                            variant="primary"
                            onClick={() => setConnecting(true)}
                        >
                            {t('data_sources.connected.connect')}
                        </Button>
                    )
                }
            />
            {connections.length === 0 ? (
                <EmptyState title={t('data_sources.connected.empty_title')}>
                    {t('data_sources.connected.empty_body')}
                </EmptyState>
            ) : (
                <ul className="divide-y divide-line border-b border-line">
                    {connections.map((connection) => (
                        <li
                            key={connection.uuid}
                            className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm"
                        >
                            <span className="min-w-0">
                                <span className="flex items-center gap-2 font-medium">
                                    {connection.environment}
                                    <Badge
                                        tone={
                                            CONNECTED_TONES[connection.status]
                                        }
                                    >
                                        {t(
                                            `data_sources.connected.statuses.${connection.status}`,
                                        )}
                                    </Badge>
                                </span>
                                <span className="block truncate font-mono text-xs text-ink-3">
                                    {connection.url}
                                </span>
                                {connection.last_error && (
                                    <span className="block text-xs wrap-break-word text-ink-2">
                                        {connection.last_error}
                                    </span>
                                )}
                            </span>
                            {project.can_administer && (
                                <span className="inline-flex flex-wrap gap-2">
                                    <Button
                                        onClick={() =>
                                            setChangingUrl(connection)
                                        }
                                    >
                                        {t('data_sources.connected.change_url')}
                                    </Button>
                                    <ConfirmButton
                                        question={t(
                                            'data_sources.connected.rotate_question',
                                        )}
                                        confirmLabel={t(
                                            'data_sources.connected.rotate',
                                        )}
                                        onConfirm={() =>
                                            router.post(
                                                connectionRotate([
                                                    project.uuid,
                                                    connection.uuid,
                                                ]).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('data_sources.connected.rotate')}
                                    </ConfirmButton>
                                    <ConfirmButton
                                        question={t(
                                            'data_sources.connected.disconnect_question',
                                            {
                                                environment:
                                                    connection.environment,
                                            },
                                        )}
                                        confirmLabel={t(
                                            'data_sources.connected.disconnect',
                                        )}
                                        onConfirm={() =>
                                            router.delete(
                                                connectionDestroy([
                                                    project.uuid,
                                                    connection.uuid,
                                                ]).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('data_sources.connected.disconnect')}
                                    </ConfirmButton>
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {changingUrl && (
                <Dialog open onOpenChange={() => setChangingUrl(null)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {t('data_sources.connected.change_url')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('data_sources.connected.change_url_body')}
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            {...connectionUpdate.form([
                                project.uuid,
                                changingUrl.uuid,
                            ])}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setChangingUrl(null)}
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        label={t('data_sources.connected.url')}
                                        htmlFor="connection-new-url"
                                        hint={t(
                                            'data_sources.connected.url_hint',
                                        )}
                                        error={errors.url}
                                    >
                                        <Input
                                            id="connection-new-url"
                                            name="url"
                                            type="url"
                                            required
                                            autoFocus
                                            maxLength={2000}
                                            className="font-mono"
                                            defaultValue={changingUrl.url}
                                            autoComplete="off"
                                        />
                                    </Field>
                                    <Field
                                        label={t(
                                            'data_sources.connected.timezone',
                                        )}
                                        htmlFor="connection-new-timezone"
                                        hint={t(
                                            'data_sources.connected.timezone_hint',
                                        )}
                                        error={errors.timezone}
                                    >
                                        <Select
                                            id="connection-new-timezone"
                                            name="timezone"
                                            defaultValue={changingUrl.timezone}
                                        >
                                            {timezones(
                                                changingUrl.timezone,
                                            ).map((zone) => (
                                                <option key={zone} value={zone}>
                                                    {zone}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <DialogFooter>
                                        <Button
                                            onClick={() => setChangingUrl(null)}
                                        >
                                            {t('common.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            disabled={processing}
                                        >
                                            {t(
                                                'data_sources.connected.save_url',
                                            )}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}
            {connecting && (
                <Dialog
                    open
                    onOpenChange={(open) => !open && setConnecting(false)}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {t('data_sources.connected.form_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('data_sources.connected.form_setup')}
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            {...connectionStore.form(project.uuid)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setConnecting(false)}
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        label={t(
                                            'data_sources.connected.environment',
                                        )}
                                        htmlFor="connection-environment"
                                        error={errors.environment}
                                    >
                                        <Input
                                            id="connection-environment"
                                            name="environment"
                                            required
                                            autoFocus
                                            maxLength={64}
                                            defaultValue="production"
                                            autoComplete="off"
                                        />
                                    </Field>
                                    <Field
                                        label={t('data_sources.connected.url')}
                                        htmlFor="connection-url"
                                        hint={t(
                                            'data_sources.connected.url_hint',
                                        )}
                                        error={errors.url}
                                    >
                                        <Input
                                            id="connection-url"
                                            name="url"
                                            type="url"
                                            required
                                            maxLength={2000}
                                            className="font-mono"
                                            placeholder="https://your-app.example/lumberjack/datasources"
                                            autoComplete="off"
                                        />
                                    </Field>
                                    <Field
                                        label={t(
                                            'data_sources.connected.timezone',
                                        )}
                                        htmlFor="connection-timezone"
                                        hint={t(
                                            'data_sources.connected.timezone_hint',
                                        )}
                                        error={errors.timezone}
                                    >
                                        <Select
                                            id="connection-timezone"
                                            name="timezone"
                                            defaultValue={browserTimezone}
                                        >
                                            {timezones(browserTimezone).map(
                                                (zone) => (
                                                    <option
                                                        key={zone}
                                                        value={zone}
                                                    >
                                                        {zone}
                                                    </option>
                                                ),
                                            )}
                                        </Select>
                                    </Field>
                                    <DialogFooter>
                                        <Button
                                            onClick={() => setConnecting(false)}
                                        >
                                            {t('common.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            disabled={processing}
                                        >
                                            {t(
                                                'data_sources.connected.connect',
                                            )}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}
            {sources.length === 0 ? (
                connections.length > 0 && (
                    <p className="px-4 py-3 text-sm text-ink-3">
                        {t('data_sources.connected.no_sources')}
                    </p>
                )
            ) : (
                <div className="relative overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                <th className={thClass}>
                                    {t('data_sources.columns.name')}
                                </th>
                                <th className={thClass}>
                                    {t('data_sources.connected.columns.shape')}
                                </th>
                                <th className={thClass}>
                                    {t(
                                        'data_sources.connected.columns.environment',
                                    )}
                                </th>
                                <th className={thClass}>
                                    {t('data_sources.columns.status')}
                                </th>
                                {project.can_administer && (
                                    <th className={thClass}>
                                        <span className="sr-only">
                                            {t('data_sources.columns.actions')}
                                        </span>
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {sources.map((source) => (
                                <tr key={source.uuid} className="align-top">
                                    <td className={cn(tdClass, 'max-w-96')}>
                                        <span className="block font-medium">
                                            {source.name}
                                        </span>
                                        <Mono>{source.key}</Mono>
                                    </td>
                                    <td className={tdClass}>
                                        <Badge>
                                            {t(
                                                `data_sources.connected.shapes.${source.shape}`,
                                            )}
                                        </Badge>
                                    </td>
                                    <td className={cn(tdClass, 'text-xs')}>
                                        {source.environment ?? ''}
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            'max-w-72 min-w-40 text-xs text-ink-2',
                                        )}
                                    >
                                        <Badge
                                            tone={
                                                CONNECTED_TONES[source.status]
                                            }
                                        >
                                            {t(
                                                `data_sources.connected.statuses.${source.status}`,
                                            )}
                                        </Badge>
                                        {source.last_error && (
                                            <span className="mt-1 block wrap-break-word text-err">
                                                {source.last_error}
                                            </span>
                                        )}
                                        {source.last_ok_at && (
                                            <span className="block text-ink-3">
                                                {t('data_sources.last_ok')}{' '}
                                                <When iso={source.last_ok_at} />
                                            </span>
                                        )}
                                    </td>
                                    {project.can_administer && (
                                        <td
                                            className={cn(
                                                tdClass,
                                                'text-right whitespace-nowrap',
                                            )}
                                        >
                                            {source.status === 'archived' && (
                                                <ConfirmButton
                                                    question={t(
                                                        'data_sources.connected.remove_question',
                                                        {
                                                            source: source.name,
                                                        },
                                                    )}
                                                    confirmLabel={t(
                                                        'data_sources.connected.remove',
                                                    )}
                                                    onConfirm={() =>
                                                        router.delete(
                                                            sourceDestroy([
                                                                project.uuid,
                                                                source.uuid,
                                                            ]).url,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {t(
                                                        'data_sources.connected.remove',
                                                    )}
                                                </ConfirmButton>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

function SourceRow({
    project,
    source,
    onEdit,
}: {
    project: ProjectHeader;
    source: DataSourceRow;
    onEdit: () => void;
}) {
    const t = useT();
    const failed = source.last_error !== null;
    const actions = project.can_administer && (
        <span className="inline-flex flex-wrap gap-2">
            <Button
                onClick={() =>
                    router.post(
                        sourceFetch([project.uuid, source.uuid]).url,
                        {},
                        { preserveScroll: true },
                    )
                }
            >
                {t('data_sources.fetch_now')}
            </Button>
            <Button onClick={onEdit}>{t('data_sources.edit')}</Button>
            <ConfirmButton
                question={t('data_sources.delete_question', {
                    source: source.name,
                })}
                confirmLabel={t('data_sources.delete')}
                onConfirm={() =>
                    router.delete(
                        sourceDestroy([project.uuid, source.uuid]).url,
                        { preserveScroll: true },
                    )
                }
            >
                {t('data_sources.delete')}
            </ConfirmButton>
        </span>
    );

    return (
        <tr className="align-top">
            <td className={cn(tdClass, 'max-w-96')}>
                <span className="flex items-center gap-2 font-medium">
                    {source.name}
                    <Badge>{t(`data_sources.formats.${source.format}`)}</Badge>
                </span>
                <span
                    className="block max-w-56 truncate font-mono text-xs text-ink-3 md:max-w-96"
                    title={source.url}
                >
                    GET {source.url}
                </span>
                {source.header_names.length > 0 && (
                    <span className="mt-1 flex flex-wrap gap-1">
                        {source.header_names.map((name) => (
                            <Badge key={name}>
                                <Mono>{name}</Mono>
                            </Badge>
                        ))}
                    </span>
                )}
                {source.preview !== null && (
                    <details className="mt-1.5 text-xs">
                        <summary className="cursor-pointer text-ink-3">
                            {t('data_sources.preview')}
                        </summary>
                        <pre className="mt-1 max-h-60 overflow-auto rounded-md border border-line bg-rail p-2 font-mono whitespace-pre-wrap">
                            {source.preview}
                        </pre>
                    </details>
                )}
                {/* Below md the actions column is hidden, so they sit here, in view. */}
                {actions && <div className="mt-2 md:hidden">{actions}</div>}
            </td>
            <td className={tdClass}>
                {source.last_fetched_at === null ? (
                    <Badge>{t('data_sources.statuses.pending')}</Badge>
                ) : (
                    <Badge tone={failed ? 'err' : 'ok'}>
                        {t(
                            failed
                                ? 'data_sources.statuses.failed'
                                : 'data_sources.statuses.ok',
                        )}
                    </Badge>
                )}
            </td>
            <td className={cn(tdClass, 'max-w-72 min-w-40 text-xs text-ink-2')}>
                <When iso={source.last_fetched_at} />
                {failed && (
                    <span className="block wrap-break-word text-err">
                        {source.last_error}
                    </span>
                )}
                {failed && source.last_ok_at && (
                    <span className="block text-ink-3">
                        {t('data_sources.last_ok')}{' '}
                        <When iso={source.last_ok_at} />
                    </span>
                )}
            </td>
            <td
                className={cn(
                    tdClass,
                    'hidden text-xs whitespace-nowrap text-ink-2 md:table-cell',
                )}
            >
                {t('data_sources.every', {}, source.interval_minutes)}
            </td>
            {project.can_administer && (
                <td
                    className={cn(
                        tdClass,
                        'hidden text-right whitespace-nowrap md:table-cell',
                    )}
                >
                    {actions}
                </td>
            )}
        </tr>
    );
}

/** A header row of the form; `kept` means it has a stored value the form never sees. */
type HeaderRow = { name: string; value: string; kept: boolean };

function SourceDialog({
    project,
    source,
    onClose,
}: {
    project: string;
    source?: DataSourceRow;
    onClose: () => void;
}) {
    const t = useT();
    const [headers, setHeaders] = useState<HeaderRow[]>(
        (source?.header_names ?? []).map((name) => ({
            name,
            value: '',
            kept: true,
        })),
    );
    const form = source
        ? sourceUpdate.form([project, source.uuid])
        : sourceStore.form(project);
    const setHeader = (index: number, changes: Partial<HeaderRow>) =>
        setHeaders(
            headers.map((h, i) => (i === index ? { ...h, ...changes } : h)),
        );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {source
                            ? t('data_sources.form.edit_title', {
                                  source: source.name,
                              })
                            : t('data_sources.form.add_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('data_sources.form.setup')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    transform={(data) => ({
                        ...data,
                        headers: headers.map(({ name, value }) => ({
                            name,
                            value,
                        })),
                    })}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field
                                    label={t('data_sources.form.name')}
                                    htmlFor="source-name"
                                    error={errors.name}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="source-name"
                                        name="name"
                                        required
                                        autoFocus
                                        maxLength={150}
                                        defaultValue={source?.name ?? ''}
                                        autoComplete="off"
                                    />
                                </Field>
                                <Field
                                    label={t('data_sources.form.url')}
                                    htmlFor="source-url"
                                    hint={t('data_sources.form.url_hint')}
                                    error={errors.url}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="source-url"
                                        name="url"
                                        type="url"
                                        required
                                        maxLength={2000}
                                        className="font-mono"
                                        defaultValue={source?.url ?? ''}
                                        placeholder="https://example.com/stats.json"
                                        autoComplete="off"
                                    />
                                </Field>
                                <Field
                                    label={t('data_sources.form.format')}
                                    htmlFor="source-format"
                                    hint={t('data_sources.form.format_hint')}
                                    error={errors.format}
                                    className="sm:col-span-2"
                                >
                                    <Select
                                        id="source-format"
                                        name="format"
                                        defaultValue={source?.format ?? 'json'}
                                    >
                                        {(
                                            [
                                                'json',
                                                'prometheus',
                                                'ical',
                                            ] as const
                                        ).map((format) => (
                                            <option key={format} value={format}>
                                                {t(
                                                    `data_sources.formats.${format}`,
                                                )}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field
                                    label={t('data_sources.form.interval')}
                                    htmlFor="source-interval"
                                    hint={t('data_sources.form.minutes')}
                                    error={errors.interval_minutes}
                                >
                                    <Input
                                        id="source-interval"
                                        name="interval_minutes"
                                        type="number"
                                        min={1}
                                        max={1440}
                                        required
                                        className="font-mono"
                                        defaultValue={
                                            source?.interval_minutes ?? 5
                                        }
                                    />
                                </Field>
                                <Field
                                    label={t('data_sources.form.timeout')}
                                    htmlFor="source-timeout"
                                    hint={t('data_sources.form.seconds')}
                                    error={errors.timeout_s}
                                >
                                    <Input
                                        id="source-timeout"
                                        name="timeout_s"
                                        type="number"
                                        min={1}
                                        max={15}
                                        required
                                        className="font-mono"
                                        defaultValue={source?.timeout_s ?? 10}
                                    />
                                </Field>
                            </div>

                            <fieldset className="flex flex-col gap-2 rounded-md border border-line p-3">
                                <legend className="px-1 text-xs text-ink-3">
                                    {t('data_sources.form.headers')}
                                </legend>
                                {headers.map((header, i) => {
                                    const error =
                                        (errors as Record<string, string>)[
                                            `headers.${i}.name`
                                        ] ??
                                        (errors as Record<string, string>)[
                                            `headers.${i}.value`
                                        ];

                                    return (
                                        <div
                                            key={i}
                                            className="flex flex-col gap-1"
                                        >
                                            <div className="flex gap-2">
                                                <Input
                                                    aria-label={t(
                                                        'data_sources.form.header_name',
                                                    )}
                                                    value={header.name}
                                                    readOnly={header.kept}
                                                    placeholder="Authorization"
                                                    className="w-44 font-mono"
                                                    autoComplete="off"
                                                    onChange={(e) =>
                                                        setHeader(i, {
                                                            name: e.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                                <Input
                                                    aria-label={t(
                                                        'data_sources.form.header_value',
                                                    )}
                                                    type="password"
                                                    value={header.value}
                                                    placeholder={
                                                        header.kept
                                                            ? t(
                                                                  'data_sources.form.keep_value',
                                                              )
                                                            : ''
                                                    }
                                                    className="min-w-0 flex-1 font-mono"
                                                    autoComplete="new-password"
                                                    onChange={(e) =>
                                                        setHeader(i, {
                                                            value: e.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                                <Button
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setHeaders(
                                                            headers.filter(
                                                                (_, j) =>
                                                                    j !== i,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    {t(
                                                        'data_sources.form.remove_header',
                                                    )}
                                                </Button>
                                            </div>
                                            {error && (
                                                <p className="text-xs text-err">
                                                    {error}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                                <div>
                                    <Button
                                        onClick={() =>
                                            setHeaders([
                                                ...headers,
                                                {
                                                    name: '',
                                                    value: '',
                                                    kept: false,
                                                },
                                            ])
                                        }
                                    >
                                        {t('data_sources.form.add_header')}
                                    </Button>
                                </div>
                                <p className="text-xs text-ink-3">
                                    {t('data_sources.form.headers_hint')}
                                </p>
                            </fieldset>

                            <DialogFooter>
                                <Button onClick={onClose}>
                                    {t('common.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing}
                                >
                                    {source
                                        ? t('data_sources.form.save')
                                        : t('data_sources.form.add')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
