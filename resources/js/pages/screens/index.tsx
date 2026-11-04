import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { Relative } from '@/components/lj/relative';
import { TopBar } from '@/components/lj/top-bar';
import {
    Button,
    buttonClass,
    Card,
    EmptyState,
    Field,
    Input,
    LinkButton,
    PageTitle,
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
import { xsrf } from '@/lib/http';
import { useT } from '@/lib/i18n';
import { useTeamLive } from '@/lib/use-live';
import {
    destroy,
    duplicate,
    edit,
    exportMethod,
    importMethod,
    show,
    store,
    update,
} from '@/routes/screens';
import { preview as importPreview } from '@/routes/screens/import';
import type { TeamShared } from '@/types/lumberjack';

type Row = {
    uuid: string;
    name: string;
    cols: number;
    rows: number;
    theme: string;
    widgets: number;
    updated_at: string | null;
};

type Template = {
    id: string;
    label: string;
    description: string;
    cols: number;
    rows: number;
    widgets: { type: string; x: number; y: number; w: number; h: number }[];
};

type ImportPreview = {
    name: string;
    cols: number;
    rows: number;
    widgets: Template['widgets'];
    notice: string;
};

/**
 * Import a screen file: picked or pasted, previewed by the server (name, grid, widgets
 * and what this team has nothing for), then created in the current team.
 */
function ImportDialog({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const t = useT();
    const [file, setFile] = useState<File | null>(null);
    const [json, setJson] = useState('');
    const [preview, setPreview] = useState<ImportPreview | null>(null);
    const [errors, setErrors] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);

    const body = () => {
        const data = new FormData();

        if (file) {
            data.append('file', file);
        } else {
            data.append('json', json);
        }

        return data;
    };

    useEffect(() => {
        setPreview(null);
        setErrors([]);

        if (!file && json.trim() === '') {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setBusy(true);
            const response = await fetch(importPreview().url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                body: body(),
                signal: controller.signal,
            }).catch(() => null);
            const result = (await response?.json().catch(() => null)) as
                | (ImportPreview & { errors?: Record<string, string[]> })
                | null;
            setBusy(false);

            if (response?.ok && result) {
                setPreview(result);
            } else {
                setErrors(
                    Object.values(result?.errors ?? {}).flat().length > 0
                        ? Object.values(result?.errors ?? {}).flat()
                        : [t('screens.import.errors.json')],
                );
            }
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [file, json]);

    const close = () => {
        setFile(null);
        setJson('');
        onClose();
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent className="sm:max-w-xl">
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(importMethod().url, body(), {
                            onSuccess: close,
                            onError: (errs) => setErrors(Object.values(errs)),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>{t('screens.import.title')}</DialogTitle>
                        <DialogDescription>
                            {t('screens.import.hint')}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label={t('screens.import.file')}
                        htmlFor="import-file"
                    >
                        <input
                            id="import-file"
                            type="file"
                            accept=".json,application/json"
                            className="text-[13px]"
                            onChange={(e) =>
                                setFile(e.currentTarget.files?.[0] ?? null)
                            }
                        />
                    </Field>
                    {!file && (
                        <Field
                            label={t('screens.import.paste')}
                            htmlFor="import-json"
                        >
                            <textarea
                                id="import-json"
                                value={json}
                                onChange={(e) => setJson(e.currentTarget.value)}
                                rows={5}
                                spellCheck={false}
                                className="rounded-md border border-line-strong bg-rail p-2.5 font-mono text-xs text-ink"
                            />
                        </Field>
                    )}
                    <div aria-live="polite" className="flex flex-col gap-2">
                        {busy && (
                            <p className="text-xs text-ink-3">
                                {t('screens.import.reading')}
                            </p>
                        )}
                        {errors.map((error) => (
                            <p key={error} className="text-xs text-err">
                                {error}
                            </p>
                        ))}
                        {preview && (
                            <div className="flex items-start gap-3 rounded-md border border-line p-3">
                                <div className="w-40 shrink-0">
                                    <TemplatePreview
                                        template={{
                                            id: 'import',
                                            label: preview.name,
                                            description: '',
                                            cols: preview.cols,
                                            rows: preview.rows,
                                            widgets: preview.widgets,
                                        }}
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <span className="text-xs text-ink-3">
                                        {t('screens.import.preview')}
                                    </span>
                                    <span className="font-medium text-ink">
                                        {preview.name}
                                    </span>
                                    <span className="text-xs text-ink-2">
                                        {t('screens.import.grid', {
                                            cols: preview.cols,
                                            rows: preview.rows,
                                        })}
                                        {', '}
                                        {t(
                                            'screens.import.widgets',
                                            {},
                                            preview.widgets.length,
                                        )}
                                    </span>
                                    <span
                                        className={`text-xs ${preview.notice ? 'text-amber' : 'text-ink-3'}`}
                                    >
                                        {preview.notice ||
                                            t('screens.import.all_found')}
                                    </span>
                                </div>
                            </div>
                        )}
                    </div>
                    <DialogFooter>
                        <Button onClick={close}>{t('common.cancel')}</Button>
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={preview === null || busy}
                        >
                            {t('screens.import.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** A thumbnail of a template's grid: each widget a block, tinted by its kind. */
function TemplatePreview({ template }: { template: Template }) {
    const tint: Record<string, string> = {
        stat: 'bg-ink-3/40',
        gauge: 'bg-ok/45',
        chart: 'bg-info/45',
        list: 'bg-ink-3/25',
        health: 'bg-amber/40',
    };

    return (
        <div
            aria-hidden="true"
            className="grid aspect-video w-full gap-[3px] rounded border border-line bg-ground p-[4px]"
            style={{
                gridTemplateColumns: `repeat(${template.cols}, minmax(0, 1fr))`,
                gridTemplateRows: `repeat(${template.rows}, minmax(0, 1fr))`,
            }}
        >
            {template.widgets.length === 0
                ? Array.from({ length: template.cols * template.rows }).map(
                      (_, i) => (
                          <span
                              key={i}
                              className="rounded-[2px] border border-dashed border-line"
                          />
                      ),
                  )
                : template.widgets.map((w, i) => (
                      <span
                          key={i}
                          className={`rounded-[2px] ${tint[w.type] ?? 'bg-panel-2'}`}
                          style={{
                              gridColumn: `${w.x + 1} / span ${w.w}`,
                              gridRow: `${w.y + 1} / span ${w.h}`,
                          }}
                      />
                  ))}
        </div>
    );
}

/** The team's status screens: open one on a TV, design it, or make another. */
export default function Screens({
    screens,
    templates,
}: {
    screens: Row[];
    templates: Template[];
}) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    // Another member adds, renames or edits a screen: the list follows.
    useTeamLive(['screens.changed'], ['screens']);
    const [creating, setCreating] = useState(false);
    const [template, setTemplate] = useState('overview');
    const [renaming, setRenaming] = useState<Row | null>(null);
    const [importing, setImporting] = useState(false);

    return (
        <>
            <Head title={t('screens.title')} />
            <TopBar
                crumbs={[{ label: team.name }, { label: t('screens.title') }]}
            />
            <PageBody>
                <PageTitle
                    title={t('screens.title')}
                    description={t('screens.description')}
                    actions={
                        <div className="flex gap-2">
                            <Button onClick={() => setImporting(true)}>
                                {t('screens.import.button')}
                            </Button>
                            <Button
                                variant="primary"
                                onClick={() => setCreating(true)}
                            >
                                {t('screens.new')}
                            </Button>
                        </div>
                    }
                />

                <Card>
                    {screens.length === 0 ? (
                        <EmptyState title={t('screens.empty.title')}>
                            {t('screens.empty.body')}
                        </EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('screens.table.name')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.table.grid')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.table.widgets')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.table.theme')}
                                        </th>
                                        <th className={thClass}>
                                            {t('screens.table.updated')}
                                        </th>
                                        <th className={thClass}>
                                            <span className="sr-only">
                                                {t('screens.table.actions')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {screens.map((screen) => (
                                        <tr key={screen.uuid}>
                                            <td className={tdClass}>
                                                <Link
                                                    href={edit(screen.uuid).url}
                                                    className="font-medium text-ink hover:text-amber"
                                                >
                                                    {screen.name}
                                                </Link>
                                            </td>
                                            <td
                                                className={`${tdClass} font-mono`}
                                            >
                                                {`${screen.cols} × ${screen.rows}`}
                                            </td>
                                            <td
                                                className={`${tdClass} font-mono`}
                                            >
                                                {screen.widgets}
                                            </td>
                                            <td className={tdClass}>
                                                {t(
                                                    `screens.theme.${screen.theme}`,
                                                )}
                                            </td>
                                            <td className={tdClass}>
                                                <Relative
                                                    iso={screen.updated_at}
                                                />
                                            </td>
                                            <td className={tdClass}>
                                                <div className="flex justify-end gap-1.5">
                                                    <a
                                                        href={
                                                            show(screen.uuid)
                                                                .url
                                                        }
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="inline-flex h-8 items-center rounded-md border border-line-strong bg-panel-2 px-3 font-medium hover:bg-line"
                                                    >
                                                        {t(
                                                            'screens.actions.open',
                                                        )}
                                                    </a>
                                                    <LinkButton
                                                        href={
                                                            edit(screen.uuid)
                                                                .url
                                                        }
                                                    >
                                                        {t(
                                                            'screens.actions.edit',
                                                        )}
                                                    </LinkButton>
                                                    <Button
                                                        onClick={() =>
                                                            setRenaming(screen)
                                                        }
                                                    >
                                                        {t(
                                                            'screens.actions.rename',
                                                        )}
                                                    </Button>
                                                    <Button
                                                        onClick={() =>
                                                            router.post(
                                                                duplicate(
                                                                    screen.uuid,
                                                                ).url,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'screens.actions.duplicate',
                                                        )}
                                                    </Button>
                                                    <a
                                                        href={
                                                            exportMethod(
                                                                screen.uuid,
                                                            ).url
                                                        }
                                                        download
                                                        title={t(
                                                            'screens.actions.export_hint',
                                                        )}
                                                        className={buttonClass()}
                                                    >
                                                        {t(
                                                            'screens.actions.export',
                                                        )}
                                                    </a>
                                                    <ConfirmButton
                                                        question={t(
                                                            'screens.actions.delete_question',
                                                            {
                                                                name: screen.name,
                                                            },
                                                        )}
                                                        confirmLabel={t(
                                                            'screens.actions.delete',
                                                        )}
                                                        onConfirm={() =>
                                                            router.delete(
                                                                destroy(
                                                                    screen.uuid,
                                                                ).url,
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'screens.actions.delete',
                                                        )}
                                                    </ConfirmButton>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </PageBody>

            <ImportDialog
                open={importing}
                onClose={() => setImporting(false)}
            />

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="sm:max-w-2xl">
                    <Form
                        {...store.form()}
                        onSuccess={() => setCreating(false)}
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>
                                        {t('screens.new')}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {t('screens.create_hint')}
                                    </DialogDescription>
                                </DialogHeader>
                                <Field
                                    label={t('screens.table.name')}
                                    htmlFor="screen-name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="screen-name"
                                        name="name"
                                        required
                                        maxLength={100}
                                        autoFocus
                                    />
                                </Field>
                                <fieldset className="flex flex-col gap-2">
                                    <legend className="mb-2 text-xs font-medium text-ink-2">
                                        {t('screens.template')}
                                    </legend>
                                    <input
                                        type="hidden"
                                        name="template"
                                        value={template}
                                    />
                                    <div
                                        role="radiogroup"
                                        aria-label={t('screens.template')}
                                        className="grid grid-cols-2 gap-2 sm:grid-cols-3"
                                    >
                                        {templates.map((item) => {
                                            const on = item.id === template;

                                            return (
                                                <button
                                                    key={item.id}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={on}
                                                    onClick={() =>
                                                        setTemplate(item.id)
                                                    }
                                                    className={`flex cursor-pointer flex-col gap-1.5 rounded-md border p-2 text-left ${on ? 'border-amber bg-amber/5' : 'border-line hover:border-line-strong'}`}
                                                >
                                                    <TemplatePreview
                                                        template={item}
                                                    />
                                                    <span className="text-[13px] font-medium text-ink">
                                                        {item.label}
                                                    </span>
                                                    <span className="text-xs leading-snug text-ink-3">
                                                        {item.description}
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </fieldset>
                                <DialogFooter>
                                    <Button onClick={() => setCreating(false)}>
                                        {t('common.cancel')}
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        disabled={processing}
                                    >
                                        {t('screens.create')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={renaming !== null}
                onOpenChange={(open) => !open && setRenaming(null)}
            >
                <DialogContent>
                    {renaming && (
                        <Form
                            {...update.form(renaming.uuid)}
                            onSuccess={() => setRenaming(null)}
                            options={{ preserveScroll: true }}
                            className="flex flex-col gap-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            {t('screens.actions.rename')}
                                        </DialogTitle>
                                        <DialogDescription>
                                            {renaming.name}
                                        </DialogDescription>
                                    </DialogHeader>
                                    <Field
                                        label={t('screens.table.name')}
                                        htmlFor="screen-rename"
                                        error={errors.name}
                                    >
                                        <Input
                                            id="screen-rename"
                                            name="name"
                                            defaultValue={renaming.name}
                                            required
                                            maxLength={100}
                                            autoFocus
                                        />
                                    </Field>
                                    <DialogFooter>
                                        <Button
                                            onClick={() => setRenaming(null)}
                                        >
                                            {t('common.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            disabled={processing}
                                        >
                                            {t('screens.actions.save')}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
