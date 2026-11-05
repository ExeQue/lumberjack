import { Form, Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
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

type Props = {
    project: ProjectHeader;
    sources: DataSourceRow[];
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

export default function DataSourcesIndex({ project, sources }: Props) {
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
            <PageBody wide>
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
                        <div className="overflow-x-auto">
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
                                        <th className={thClass}>
                                            {t('data_sources.columns.every')}
                                        </th>
                                        {project.can_administer && (
                                            <th className={thClass}>
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

    return (
        <tr className="align-top">
            <td className={cn(tdClass, 'max-w-96')}>
                <span className="flex items-center gap-2 font-medium">
                    {source.name}
                    <Badge>{t(`data_sources.formats.${source.format}`)}</Badge>
                </span>
                <span
                    className="block truncate font-mono text-xs text-ink-3"
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
            <td className={cn(tdClass, 'max-w-72 text-xs text-ink-2')}>
                <When iso={source.last_fetched_at} />
                {failed && (
                    <span className="block text-err">{source.last_error}</span>
                )}
                {failed && source.last_ok_at && (
                    <span className="block text-ink-3">
                        {t('data_sources.last_ok')}{' '}
                        <When iso={source.last_ok_at} />
                    </span>
                )}
            </td>
            <td className={cn(tdClass, 'text-xs whitespace-nowrap text-ink-2')}>
                {t('data_sources.every', {}, source.interval_minutes)}
            </td>
            {project.can_administer && (
                <td className={cn(tdClass, 'text-right whitespace-nowrap')}>
                    <span className="inline-flex gap-2">
                        <Button
                            onClick={() =>
                                router.post(
                                    sourceFetch([project.uuid, source.uuid])
                                        .url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('data_sources.fetch_now')}
                        </Button>
                        <Button onClick={onEdit}>
                            {t('data_sources.edit')}
                        </Button>
                        <ConfirmButton
                            question={t('data_sources.delete_question', {
                                source: source.name,
                            })}
                            confirmLabel={t('data_sources.delete')}
                            onConfirm={() =>
                                router.delete(
                                    sourceDestroy([project.uuid, source.uuid])
                                        .url,
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('data_sources.delete')}
                        </ConfirmButton>
                    </span>
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
