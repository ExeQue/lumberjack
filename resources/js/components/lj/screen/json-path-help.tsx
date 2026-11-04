import {
    useCallback,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { WithRelative } from '@/components/lj/relative';
import { Button, Input, Label } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import { preview as previewRoute } from '@/routes/screens/data-sources';

/** A JSONPath, or for Prometheus a metric (kind family) or a selector, with its type. */
type Suggestion = {
    path: string;
    sample: unknown;
    kind: string;
    type?: string;
};

/** What the server says about a data source's latest body (PreviewDataSourceAction). */
export type JsonPreview = {
    fetched_at: string | null;
    body: string | null;
    truncated: boolean;
    suggestions: Suggestion[];
    item_suggestions: Suggestion[];
    result: {
        value?: number | null;
        count: number;
        reason:
            | 'no_match'
            | 'several'
            | 'not_number'
            | 'invalid'
            | 'no_rate'
            | null;
        /** A Prometheus counter, read per minute. */
        counter?: boolean;
        rows?: { label: string; value: number | string | null }[];
    } | null;
};

/** The fields whose JSONPath this helps with. */
export const PATH_FIELDS = ['json_path', 'label_path', 'value_path'];

const DEBOUNCE_MS = 300;

/**
 * The preview of the picked data source for the paths typed, asked again once typing
 * pauses; a request still running when they change again is cancelled.
 */
export function useJsonPreview(
    screen: string | undefined,
    source: string | undefined,
    type: string,
    paths: { path: string; label_path: string; value_path: string },
): JsonPreview | null {
    const [preview, setPreview] = useState<{
        source: string;
        data: JsonPreview;
    } | null>(null);
    const { path, label_path, value_path } = paths;

    useEffect(() => {
        if (!screen || !source) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            const query = Object.fromEntries(
                Object.entries({ type, path, label_path, value_path }).filter(
                    ([, v]) => v !== '',
                ),
            );
            fetch(previewRoute({ screen, dataSource: source }, { query }).url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((data: JsonPreview | null) =>
                    setPreview(data ? { source, data } : null),
                )
                .catch(() => {});
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [screen, source, type, path, label_path, value_path]);

    // Another source's answer is not shown while the new one loads.
    return preview && preview.source === source ? preview.data : null;
}

const IDENTIFIER = /^[A-Za-z_][A-Za-z0-9_]*$/;

/** .key, or ['key'] for a key that is not an identifier (as the server writes paths). */
const step = (key: string) =>
    IDENTIFIER.test(key) ? `.${key}` : `['${key.replace(/['\\]/g, '\\$&')}']`;

/** A JSON number, or a numeric string, which the server reads as a number too. */
const numeric = (value: unknown) =>
    typeof value === 'number' ||
    (typeof value === 'string' &&
        value.trim() !== '' &&
        !Number.isNaN(Number(value)));

/** What the JSONPath (or selector) reads now: the value, the items, or why not. */
export function PathResult({
    type,
    preview,
}: {
    type: string;
    preview: JsonPreview;
}) {
    const t = useT();
    const result = preview.result;

    if (!result) {
        return null;
    }

    return (
        <span className="text-xs text-ink-3">
            {result.reason === 'several'
                ? t(`screens.fields.json_path.help.several.${type}`, {
                      count: result.count,
                  })
                : result.reason
                  ? t(`screens.fields.json_path.help.${result.reason}`)
                  : result.rows
                    ? [
                          t(
                              'screens.fields.json_path.help.items',
                              { count: result.count },
                              result.count,
                          ),
                          ...result.rows.map((r) =>
                              [r.label, r.value]
                                  .filter((v) => v !== null && v !== '')
                                  .join(' '),
                          ),
                      ].join(' · ')
                    : t('screens.fields.json_path.help.matches', {
                          value: String(result.value),
                      })}
            {result.counter &&
                !result.reason &&
                ` (${t('screens.fields.json_path.help.per_min')})`}
        </span>
    );
}

/** The paths the body offers for a field that contain what is typed, picked with a click. */
function Suggestions({
    field,
    value,
    preview,
    onPick,
}: {
    field: string;
    value: string;
    preview: JsonPreview;
    onPick: (path: string) => void;
}) {
    const t = useT();
    const typed = value.trim().toLowerCase();
    const offered = (
        field === 'json_path' ? preview.suggestions : preview.item_suggestions
    ).filter((s) => s.path.toLowerCase().includes(typed) && s.path !== value);
    const sample = (s: Suggestion) =>
        s.kind === 'family'
            ? t(
                  'screens.fields.json_path.help.series',
                  { type: s.type ?? '', count: Number(s.sample) },
                  Number(s.sample),
              )
            : s.kind === 'selector'
              ? t(
                    'screens.fields.json_path.help.selector_series',
                    { count: Number(s.sample) },
                    Number(s.sample),
                )
              : s.kind === 'array'
                ? t(
                      'screens.fields.json_path.help.items',
                      { count: Number(s.sample) },
                      Number(s.sample),
                  )
                : typeof s.sample === 'string'
                  ? JSON.stringify(s.sample.slice(0, 40))
                  : String(s.sample);

    if (offered.length === 0) {
        return null;
    }

    return (
        <ul
            aria-label={t('screens.fields.json_path.help.suggestions')}
            className="max-h-60 overflow-y-auto rounded-md border border-line"
        >
            {offered.map((s) => (
                <li key={s.path}>
                    <button
                        type="button"
                        onClick={() => onPick(s.path)}
                        className="flex w-full items-baseline justify-between gap-2 px-2 py-0.5 text-left text-xs hover:bg-rail"
                    >
                        <span className="truncate font-mono">{s.path}</span>
                        <span className="shrink-0 truncate text-ink-3">
                            {sample(s)}
                        </span>
                    </button>
                </li>
            ))}
        </ul>
    );
}

/** A JSON value pretty-printed, where what canPick allows is a button that picks its path. */
function JsonTree({
    value,
    path,
    name,
    last,
    canPick,
    onPick,
}: {
    value: unknown;
    path: string;
    name?: string;
    last: boolean;
    canPick: (value: unknown) => boolean;
    onPick: (path: string) => void;
}): ReactNode {
    const head = name !== undefined && (
        <span className="text-ink-3">{JSON.stringify(name)}: </span>
    );
    const tail = last ? '' : ',';
    const mark = (text: string) =>
        canPick(value) ? (
            <button
                type="button"
                title={path}
                onClick={() => onPick(path)}
                className="cursor-pointer rounded-sm text-amber hover:bg-line hover:underline"
            >
                {text}
            </button>
        ) : (
            text
        );

    if (value === null || typeof value !== 'object') {
        return (
            <div>
                {head}
                {mark(JSON.stringify(value))}
                {tail}
            </div>
        );
    }

    const array = Array.isArray(value);
    const entries: [string, unknown][] = array
        ? value.map((v, i) => [String(i), v])
        : Object.entries(value);
    const [open, close] = array ? ['[', ']'] : ['{', '}'];

    if (entries.length === 0) {
        return (
            <div>
                {head}
                {open}
                {close}
                {tail}
            </div>
        );
    }

    return (
        <div>
            <div>
                {head}
                {mark(open)}
            </div>
            <div className="pl-4">
                {entries.map(([key, child], i) => (
                    <JsonTree
                        key={key}
                        value={child}
                        path={path + (array ? `[${key}]` : step(key))}
                        name={array ? undefined : key}
                        last={i === entries.length - 1}
                        canPick={canPick}
                        onPick={onPick}
                    />
                ))}
            </div>
            <div>
                {close}
                {tail}
            </div>
        </div>
    );
}

/**
 * The paths of an External JSON value in short, and a dialog to set them: the latest
 * response on one side (a number, or for a list an array, picks its path with a click),
 * the paths with what they read and the paths on offer on the other. Edits stay in the
 * dialog until applied. The dialog is portalled out of the inspector, but React events
 * bubble through portals: they stop here (overlay included), so they never reach the
 * designer's handlers.
 */
export function PathConfig({
    screen,
    source,
    type,
    prometheus,
    fields,
    values,
    inherited,
    preview,
    onApply,
}: {
    screen: string | undefined;
    source: string | undefined;
    type: string;
    prometheus: boolean;
    /** The path fields the value has, json_path first. */
    fields: string[];
    values: Record<string, unknown>;
    /** A rotated value's paths follow the widget's where it sets none. */
    inherited?: Record<string, unknown>;
    preview: JsonPreview | null;
    onApply: (changes: Record<string, string>) => void;
}) {
    const t = useT();
    const id = useId();
    const firstInput = useRef<HTMLInputElement>(null);
    // The paths being edited, while the dialog is open.
    const [draft, setDraft] = useState<Record<string, string> | null>(null);
    const text = (from: Record<string, unknown> | undefined, key: string) => {
        const value = from?.[key];

        return typeof value === 'string' ? value : '';
    };
    const own = (key: string) => draft?.[key] ?? text(values, key);
    const live = (key: string) => own(key) || text(inherited, key);
    const local = useJsonPreview(draft ? screen : undefined, source, type, {
        path: live('json_path'),
        label_path: live('label_path'),
        value_path: live('value_path'),
    });
    // Until the dialog's own answer arrives, the inspector's (same body).
    const shown = (draft && local) || preview;
    const list = type === 'list';
    const pick = useCallback(
        (path: string) => setDraft((d) => ({ ...d, json_path: path })),
        [],
    );
    const body = shown?.body ?? null;
    const tree = useMemo(() => {
        if (body === null || prometheus || shown?.truncated) {
            return body;
        }

        try {
            return (
                <JsonTree
                    value={JSON.parse(body)}
                    path="$"
                    last
                    canPick={list ? Array.isArray : numeric}
                    onPick={pick}
                />
            );
        } catch {
            return body;
        }
    }, [body, prometheus, shown?.truncated, list, pick]);

    const stop = (e: { stopPropagation: () => void }) => e.stopPropagation();
    const label = (key: string) =>
        key === 'json_path' && prometheus
            ? t('screens.fields.json_path.selector')
            : t(`screens.fields.${key}.label`);
    const set = fields.map(live).filter((v) => v !== '');
    const open = () =>
        setDraft(Object.fromEntries(fields.map((k) => [k, text(values, k)])));

    return (
        <div className="col-span-2 flex flex-col gap-1">
            <span className="text-xs text-ink-2">{label('json_path')}</span>
            {set.length > 0 ? (
                <div className="flex items-center gap-2">
                    <code
                        className="min-w-0 flex-1 truncate font-mono text-xs text-ink"
                        title={set.join('\n')}
                    >
                        {set.join(' · ')}
                    </code>
                    <Button className="shrink-0" onClick={open}>
                        {t(
                            prometheus
                                ? 'screens.fields.json_path.help.configure_selector'
                                : 'screens.fields.json_path.help.configure',
                        )}
                    </Button>
                </div>
            ) : (
                <Button variant="primary" onClick={open}>
                    {t('screens.fields.json_path.help.choose')}
                </Button>
            )}
            {preview && set.length > 0 && (
                <PathResult type={type} preview={preview} />
            )}
            {preview?.body === null && (
                <span className="text-xs text-ink-3">
                    {t('screens.fields.json_path.help.not_fetched')}
                </span>
            )}
            <span
                className="contents"
                onClick={stop}
                onPointerDown={stop}
                onKeyDown={stop}
            >
                <Dialog
                    open={draft !== null}
                    onOpenChange={(next) => !next && setDraft(null)}
                >
                    <DialogContent
                        className="flex max-h-[90vh] flex-col overflow-y-auto sm:max-w-5xl"
                        onOpenAutoFocus={(e) => {
                            e.preventDefault();
                            firstInput.current?.focus();
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>
                                {t(
                                    prometheus
                                        ? 'screens.fields.json_path.help.configure_selector'
                                        : 'screens.fields.json_path.help.configure',
                                )}
                            </DialogTitle>
                            <DialogDescription>
                                {!prometheus &&
                                    tree !== body &&
                                    t(
                                        list
                                            ? 'screens.fields.json_path.help.pick_array'
                                            : 'screens.fields.json_path.help.pick_number',
                                    )}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            className="grid min-h-0 gap-4 md:grid-cols-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                onApply(draft ?? {});
                                setDraft(null);
                            }}
                        >
                            <div className="flex min-w-0 flex-col gap-1">
                                <span className="text-xs text-ink-2">
                                    {t('screens.fields.json_path.help.latest')}
                                    {shown?.fetched_at && (
                                        <span className="text-ink-3">
                                            {' · '}
                                            <WithRelative
                                                iso={shown.fetched_at}
                                            >
                                                {(time) =>
                                                    t(
                                                        'screens.fields.json_path.help.fetched',
                                                        { time },
                                                    )
                                                }
                                            </WithRelative>
                                            {shown.truncated &&
                                                ' · ' +
                                                    t(
                                                        'screens.fields.json_path.help.truncated',
                                                    )}
                                        </span>
                                    )}
                                </span>
                                {body === null ? (
                                    <span className="text-xs text-ink-3">
                                        {shown &&
                                            t(
                                                'screens.fields.json_path.help.not_fetched',
                                            )}
                                    </span>
                                ) : (
                                    <div className="max-h-[40vh] overflow-auto rounded-md border border-line bg-rail/40 p-2 font-mono text-[11px] leading-snug whitespace-pre md:max-h-[70vh]">
                                        {tree}
                                    </div>
                                )}
                            </div>
                            <div className="flex min-w-0 flex-col gap-3">
                                {fields.map((key, i) => (
                                    <div
                                        key={key}
                                        className="flex flex-col gap-1"
                                    >
                                        <Label
                                            htmlFor={`${id}-${key}`}
                                            className="text-xs text-ink-2"
                                        >
                                            {label(key)}
                                        </Label>
                                        <Input
                                            id={`${id}-${key}`}
                                            ref={
                                                i === 0 ? firstInput : undefined
                                            }
                                            className="font-mono"
                                            value={own(key)}
                                            placeholder={
                                                text(inherited, key) ||
                                                (key === 'json_path' &&
                                                prometheus
                                                    ? t(
                                                          'screens.fields.json_path.selector_placeholder',
                                                      )
                                                    : t(
                                                          `screens.fields.${key}.placeholder`,
                                                      ))
                                            }
                                            onChange={(e) =>
                                                setDraft((d) => ({
                                                    ...d,
                                                    [key]: e.target.value,
                                                }))
                                            }
                                        />
                                        {shown && key === 'json_path' && (
                                            <PathResult
                                                type={type}
                                                preview={shown}
                                            />
                                        )}
                                        {shown && (
                                            <Suggestions
                                                field={key}
                                                value={live(key)}
                                                preview={shown}
                                                onPick={(path) =>
                                                    setDraft((d) => ({
                                                        ...d,
                                                        [key]: path,
                                                    }))
                                                }
                                            />
                                        )}
                                    </div>
                                ))}
                                <div className="mt-auto flex justify-end gap-2 pt-2">
                                    <Button onClick={() => setDraft(null)}>
                                        {t('common.cancel')}
                                    </Button>
                                    <Button type="submit" variant="primary">
                                        {t(
                                            'screens.fields.json_path.help.apply',
                                        )}
                                    </Button>
                                </div>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </span>
        </div>
    );
}
