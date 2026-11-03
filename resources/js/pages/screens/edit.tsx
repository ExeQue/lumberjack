import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { DragEvent, KeyboardEvent, PointerEvent } from 'react';
import { toast } from 'sonner';
import { useNow } from '@/components/lj/relative';
import {
    ScreenSettings,
    WidgetInspector,
} from '@/components/lj/screen/inspector';
import type { Catalog } from '@/components/lj/screen/inspector';
import { newWidgetId, placements, themeAt } from '@/components/lj/screen/lib';
import type {
    Schema,
    Screen,
    Sources,
    Widget,
    WidgetKind,
} from '@/components/lj/screen/types';
import { DATA_KINDS, LAYOUT_KINDS } from '@/components/lj/screen/types';
import { SharePanel } from '@/components/lj/screen/share-panel';
import type { Sharing } from '@/components/lj/screen/share-panel';
import { TvInstructions } from '@/components/lj/screen/tv-instructions';
import type { TvInstructionsProps } from '@/components/lj/screen/tv-instructions';
import { useScreenData } from '@/components/lj/screen/use-screen-data';
import { WidgetTile } from '@/components/lj/screen/widgets';
import { Button, buttonClass, Input, Select } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { xsrf } from '@/lib/http';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    index as screensIndex,
    layout as layoutRoute,
    show as showRoute,
    update as updateRoute,
    viewers as viewersRoute,
    widget as widgetRoute,
} from '@/routes/screens';

type Props = {
    screen: Screen;
    projects: { uuid: string; name: string }[];
    data_url: string;
    schema: Schema;
    sources: Sources;
    environments: string[];
    checks: Catalog['checks'];
    limits: {
        min_cols: number;
        max_cols: number;
        min_rows: number;
        max_rows: number;
        max_widgets: number;
    };
    sharing: Sharing;
    tv: TvInstructionsProps;
};

const SAVE_DELAY_MS = 300;
const VIEWERS_EVERY_MS = 15_000;
const COLS = [2, 3, 4, 5, 6, 8, 10, 12];
const PERIODS = ['1h', '24h', '7d', '30d'];

/** What a new widget of each kind starts as. */
const STARTERS: Record<WidgetKind, Pick<Widget, 'source' | 'w' | 'h'>> = {
    stat: { source: 'requests', w: 1, h: 1 },
    gauge: { source: 'error_rate', w: 1, h: 1 },
    chart: { source: 'requests_errors', w: 2, h: 2 },
    list: { source: 'latest_issues', w: 2, h: 2 },
    health: { source: 'checks', w: 1, h: 1 },
    clock: { source: null, w: 1, h: 1 },
    empty: { source: null, w: 1, h: 1 },
};

type Settings = Pick<
    Screen,
    'name' | 'theme' | 'light_from' | 'light_to' | 'timezone' | 'scope'
>;

const SETTINGS: (keyof Settings)[] = [
    'name',
    'theme',
    'light_from',
    'light_to',
    'timezone',
    'scope',
];

async function put(url: string, body: unknown): Promise<void> {
    const response = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const json = (await response.json().catch(() => null)) as {
            message?: string;
        } | null;

        throw new Error(json?.message ?? `HTTP ${response.status}`);
    }
}

/**
 * Only one widget's settings differ, in the same place and size: that widget alone is
 * saved. Anything else (added, removed, moved, resized, the grid) saves the layout.
 */
function onlyWidget(before: Screen, after: Screen): Widget | null | undefined {
    if (
        before.cols !== after.cols ||
        before.rows !== after.rows ||
        before.widgets.length !== after.widgets.length
    ) {
        return undefined;
    }

    const changed = after.widgets.filter((w, i) => {
        const old = before.widgets[i];

        return JSON.stringify(old) !== JSON.stringify(w);
    });

    if (changed.length === 0) {
        return null;
    }

    const i = after.widgets.indexOf(changed[0]);
    const old = before.widgets[i];

    return changed.length === 1 &&
        old.id === changed[0].id &&
        old.w === changed[0].w &&
        old.h === changed[0].h
        ? changed[0]
        : undefined;
}

/**
 * The screen designer: a palette of widget types, the screen as its grid and the
 * selected widget's settings. Every change saves itself shortly after and reaches open
 * kiosks live; the grid previews real data, or an incident.
 */
export default function EditScreen(props: Props) {
    const t = useT();
    const now = useNow();
    const [screen, setScreen] = useState<Screen>(props.screen);
    const [saved, setSaved] = useState<Screen>(props.screen);
    const [selected, setSelected] = useState<string | null>(
        props.screen.widgets[0]?.id ?? null,
    );
    const [status, setStatus] = useState<'saved' | 'saving' | 'error'>('saved');
    const [viewers, setViewers] = useState<number | null>(null);
    const [incident, setIncident] = useState(false);
    const [query, setQuery] = useState('');
    const [closed, setClosed] = useState<string[]>([]);
    const [dropTarget, setDropTarget] = useState<string | null>(null);
    const [dialog, setDialog] = useState<'tv' | 'share' | null>(null);
    const drag = useRef<{ kind?: WidgetKind; tile?: string } | null>(null);
    const resizing = useRef<{
        id: string;
        x: number;
        y: number;
        w: number;
        h: number;
        col: number;
        row: number;
    } | null>(null);
    const grid = useRef<HTMLDivElement>(null);
    const focusAfter = useRef<string | null>(null);
    const timer = useRef<number | null>(null);
    const savedRef = useRef<Screen>(props.screen);
    const latest = useRef<Screen>(props.screen);
    const { schema, sources } = props;
    const catalog: Catalog = {
        schema,
        sources,
        projects: props.projects,
        environments: props.environments,
        checks: props.checks,
        periods: PERIODS,
    };
    // The data follows what is saved, so a widget refetches once its new settings are stored.
    const { data } = useScreenData(
        props.data_url,
        saved,
        schema,
        props.projects.map((p) => p.uuid),
    );

    const save = async () => {
        timer.current = null;
        const before = savedRef.current;
        const after = latest.current;
        const settings = SETTINGS.filter(
            (key) => JSON.stringify(before[key]) !== JSON.stringify(after[key]),
        );
        const one = onlyWidget(before, after);

        try {
            if (settings.length > 0) {
                await put(
                    updateRoute(after.uuid).url,
                    Object.fromEntries(
                        settings.map((key) => [key, after[key]]),
                    ),
                );
            }

            if (one) {
                await put(
                    widgetRoute({ screen: after.uuid, widget: one.id }).url,
                    {
                        widget: one,
                    },
                );
            } else if (one === undefined) {
                await put(layoutRoute(after.uuid).url, {
                    cols: after.cols,
                    rows: after.rows,
                    widgets: after.widgets,
                });
            }

            savedRef.current = after;
            setSaved(after);
            setStatus(latest.current === after ? 'saved' : 'saving');
        } catch (error) {
            setStatus('error');
            toast.error(
                t('screens.designer.not_saved', {
                    reason: (error as Error).message,
                }),
            );
        }
    };

    const change = (next: (current: Screen) => Screen) => {
        const updated = next(latest.current);
        latest.current = updated;
        setScreen(updated);
        setStatus('saving');

        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => void save(), SAVE_DELAY_MS);
    };

    const setWidgets = (fn: (widgets: Widget[]) => Widget[]) =>
        change((s) => ({ ...s, widgets: fn(s.widgets) }));

    const patch = (id: string, fields: Partial<Widget>) =>
        setWidgets((widgets) =>
            widgets.map((w) => (w.id === id ? { ...w, ...fields } : w)),
        );

    const add = (kind: WidgetKind, at?: number) => {
        if (screen.widgets.length >= props.limits.max_widgets) {
            toast.error(t('screens.designer.too_many'));

            return;
        }

        const starter = STARTERS[kind];
        const widget: Widget = {
            id: newWidgetId(),
            type: kind,
            source: starter.source,
            rotate: [],
            w: Math.min(starter.w, screen.cols),
            h: Math.min(starter.h, screen.rows),
            title: null,
            options: {},
            scope: {},
        };
        setWidgets((widgets) => {
            const next = widgets.slice();
            next.splice(at ?? next.length, 0, widget);

            return next;
        });
        setSelected(widget.id);
    };

    const move = (id: string, to: number) =>
        setWidgets((widgets) => {
            const from = widgets.findIndex((w) => w.id === id);

            if (from < 0) {
                return widgets;
            }

            const next = widgets.slice();
            const [widget] = next.splice(from, 1);
            next.splice(Math.max(0, Math.min(to, next.length)), 0, widget);

            return next;
        });

    const remove = (id: string) => {
        setWidgets((widgets) => widgets.filter((w) => w.id !== id));
        setSelected(null);
    };

    const setGrid = (cols: number, rows: number) =>
        change((s) => ({
            ...s,
            cols,
            rows,
            // Widgets never get larger than the screen: those that no longer fit shrink.
            widgets: s.widgets.map((w) => ({
                ...w,
                w: Math.min(w.w, cols),
                h: Math.min(w.h, rows),
            })),
        }));

    // Save what is pending before leaving.
    useEffect(() => {
        const flush = () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
                void save();
            }
        };
        window.addEventListener('beforeunload', flush);

        return () => {
            window.removeEventListener('beforeunload', flush);
            flush();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // How many kiosks show the screen now.
    useEffect(() => {
        const ask = () =>
            fetch(viewersRoute(props.screen.uuid).url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((r) => (r.ok ? r.json() : { count: null }))
                .then((r: { count: number | null }) => setViewers(r.count))
                .catch(() => setViewers(null));
        void ask();
        const interval = window.setInterval(ask, VIEWERS_EVERY_MS);

        return () => window.clearInterval(interval);
    }, [props.screen.uuid]);

    // A widget moved with the keyboard keeps the focus.
    useEffect(() => {
        if (focusAfter.current) {
            grid.current
                ?.querySelector<HTMLElement>(
                    `[data-widget="${focusAfter.current}"]`,
                )
                ?.focus();
            focusAfter.current = null;
        }
    });

    const onKeyDown = (widget: Widget, index: number) => (e: KeyboardEvent) => {
        if (e.target !== e.currentTarget) {
            return;
        }

        if (e.key === 'Delete' || e.key === 'Backspace') {
            e.preventDefault();
            remove(widget.id);

            return;
        }

        if (e.key === 'Escape') {
            setSelected(null);

            return;
        }

        if (!e.key.startsWith('Arrow')) {
            return;
        }

        e.preventDefault();
        setSelected(widget.id);
        focusAfter.current = widget.id;

        if (e.shiftKey) {
            const dw =
                e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
            const dh = e.key === 'ArrowDown' ? 1 : e.key === 'ArrowUp' ? -1 : 0;
            patch(widget.id, {
                w: Math.max(1, Math.min(screen.cols, widget.w + dw)),
                h: Math.max(1, Math.min(screen.rows, widget.h + dh)),
            });

            return;
        }

        const step = {
            ArrowLeft: -1,
            ArrowRight: 1,
            ArrowUp: -screen.cols,
            ArrowDown: screen.cols,
        }[e.key];

        if (step !== undefined) {
            move(widget.id, index + step);
        }
    };

    const onResizeStart =
        (widget: Widget) => (e: PointerEvent<HTMLSpanElement>) => {
            e.preventDefault();
            e.stopPropagation();
            const rect = grid.current?.getBoundingClientRect();

            if (!rect) {
                return;
            }

            e.currentTarget.setPointerCapture(e.pointerId);
            const gap = 10;
            const pad = 14;
            resizing.current = {
                id: widget.id,
                x: e.clientX,
                y: e.clientY,
                w: widget.w,
                h: widget.h,
                col:
                    (rect.width - 2 * pad - (screen.cols - 1) * gap) /
                        screen.cols +
                    gap,
                row:
                    (rect.height - 2 * pad - (screen.rows - 1) * gap) /
                        screen.rows +
                    gap,
            };
            setSelected(widget.id);
        };

    const onResizeMove = (e: PointerEvent) => {
        const r = resizing.current;

        if (!r) {
            return;
        }

        const w = Math.max(
            1,
            Math.min(screen.cols, r.w + Math.round((e.clientX - r.x) / r.col)),
        );
        const h = Math.max(
            1,
            Math.min(screen.rows, r.h + Math.round((e.clientY - r.y) / r.row)),
        );
        const current = latest.current.widgets.find((x) => x.id === r.id);

        if (current && (current.w !== w || current.h !== h)) {
            patch(r.id, { w, h });
        }
    };

    const onDrop = (at?: number) => (e: DragEvent) => {
        const d = drag.current;

        if (!d) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        drag.current = null;
        setDropTarget(null);

        if (d.kind) {
            add(d.kind, at);
        } else if (d.tile) {
            move(d.tile, at ?? screen.widgets.length);
        }
    };

    const allowDrop = (target: string) => (e: DragEvent) => {
        if (!drag.current) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        if (dropTarget !== target) {
            setDropTarget(target);
        }
    };

    const q = query.trim().toLowerCase();
    const groups = [
        { id: 'data', kinds: DATA_KINDS },
        { id: 'layout', kinds: LAYOUT_KINDS },
    ]
        .map((group) => ({
            ...group,
            items: group.kinds.filter((kind) => {
                if (!q) {
                    return true;
                }

                // A search matches the sources a type shows too.
                const haystack = [
                    t(`screens.kinds.${kind}`),
                    t(`screens.hints.${kind}`),
                    ...(sources[kind] ?? []).map((s) => s.label),
                ]
                    .join(' ')
                    .toLowerCase();

                return haystack.includes(q);
            }),
        }))
        .filter((group) => group.items.length > 0);

    const place = placements(screen.widgets, screen.cols, screen.rows);
    const hiddenCount = place.filter((p) => !p.fits).length;
    const current = screen.widgets.find((w) => w.id === selected) ?? null;
    const theme = themeAt(screen, now);
    const statusText =
        status === 'saving'
            ? t('screens.designer.saving')
            : status === 'error'
              ? t('screens.designer.error')
              : viewers !== null
                ? t('screens.designer.saved_live', {}, viewers)
                : t('screens.designer.saved');

    return (
        <div className="flex min-h-screen flex-col bg-ground text-[13px] text-ink">
            <Head title={t('screens.designer.title', { name: screen.name })} />
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-panel px-6 py-3.5">
                <nav
                    aria-label={t('common.breadcrumb')}
                    className="flex items-center gap-2.5 text-ink-2"
                >
                    <Link href={screensIndex().url} className="hover:text-ink">
                        {t('screens.title')}
                    </Link>
                    <span aria-hidden="true">/</span>
                    <h1
                        aria-current="page"
                        className="text-[15px] font-semibold text-ink"
                    >
                        {screen.name}
                    </h1>
                </nav>
                <div className="flex flex-wrap items-center gap-3">
                    <span
                        aria-live="polite"
                        className="flex items-center gap-2 text-ink-2"
                    >
                        <span
                            aria-hidden="true"
                            className={cn(
                                'size-2 rounded-full',
                                status === 'saving'
                                    ? 'bg-amber'
                                    : status === 'error'
                                      ? 'bg-err'
                                      : 'bg-ok',
                            )}
                        />
                        {statusText}
                    </span>
                    <Button onClick={() => setDialog('tv')}>
                        {t('screens.open_tv.button')}
                    </Button>
                    <Button onClick={() => setDialog('share')}>
                        {t('screens.share.button')}
                        {props.sharing.url !== null && (
                            <span
                                aria-hidden="true"
                                className="size-1.5 rounded-full bg-ok"
                            />
                        )}
                    </Button>
                    <a
                        href={showRoute(screen.uuid).url}
                        target="_blank"
                        rel="noreferrer"
                        className={buttonClass('primary')}
                    >
                        {t('screens.designer.open_kiosk')}
                    </a>
                </div>
            </header>
            <Dialog
                open={dialog !== null}
                onOpenChange={(open) => !open && setDialog(null)}
            >
                <DialogContent className="border-line bg-panel sm:max-w-xl">
                    <DialogTitle>
                        {dialog === 'share'
                            ? t('screens.share.title')
                            : t('screens.open_tv.title')}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {dialog === 'share'
                            ? t('screens.share.hint')
                            : t('screens.open_tv.step_pick')}
                    </DialogDescription>
                    {dialog === 'share' ? (
                        <SharePanel
                            screen={screen.uuid}
                            sharing={props.sharing}
                        />
                    ) : (
                        <TvInstructions {...props.tv} />
                    )}
                </DialogContent>
            </Dialog>

            <div className="flex min-h-0 flex-1 flex-wrap lg:flex-nowrap">
                <aside
                    aria-label={t('screens.palette.title')}
                    className="flex w-full flex-col gap-2 border-line p-5 lg:w-[240px] lg:flex-none lg:border-r"
                >
                    <h2 className="text-[11px] font-semibold tracking-wide text-ink-3 uppercase">
                        {t('screens.palette.title')}
                    </h2>
                    <Input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('screens.palette.search')}
                        aria-label={t('screens.palette.search')}
                    />
                    {groups.map((group) => {
                        const open = q ? true : !closed.includes(group.id);

                        return (
                            <div
                                key={group.id}
                                role="group"
                                aria-label={t(`screens.palette.${group.id}`)}
                                className="flex flex-col gap-1.5"
                            >
                                <button
                                    type="button"
                                    aria-expanded={open}
                                    onClick={() =>
                                        setClosed((c) =>
                                            c.includes(group.id)
                                                ? c.filter(
                                                      (x) => x !== group.id,
                                                  )
                                                : [...c, group.id],
                                        )
                                    }
                                    className="flex items-center justify-between pt-1.5 text-xs font-semibold tracking-wide text-ink-2"
                                >
                                    <span>
                                        {t(`screens.palette.${group.id}`)}
                                    </span>
                                    <span
                                        className="text-ink-3"
                                        aria-hidden="true"
                                    >
                                        {group.items.length} {open ? '▾' : '▸'}
                                    </span>
                                </button>
                                {open &&
                                    group.items.map((kind) => (
                                        <button
                                            key={kind}
                                            type="button"
                                            draggable
                                            onDragStart={(e) => {
                                                e.dataTransfer.setData(
                                                    'text/plain',
                                                    kind,
                                                );
                                                e.dataTransfer.effectAllowed =
                                                    'copy';
                                                drag.current = { kind };
                                            }}
                                            onDragEnd={() => {
                                                drag.current = null;
                                                setDropTarget(null);
                                            }}
                                            onClick={() => add(kind)}
                                            className="flex cursor-grab flex-col gap-0.5 rounded-lg border border-line bg-panel px-3 py-2 text-left hover:border-amber"
                                        >
                                            <span className="text-sm font-semibold">
                                                {t(`screens.kinds.${kind}`)}
                                            </span>
                                            <span className="text-xs text-ink-3">
                                                {t(`screens.hints.${kind}`)}
                                            </span>
                                        </button>
                                    ))}
                            </div>
                        );
                    })}
                    {groups.length === 0 && (
                        <span className="text-xs text-ink-3">
                            {t('screens.palette.none')}
                        </span>
                    )}
                    <span className="text-xs text-ink-3">
                        {t('screens.palette.hint')}
                    </span>
                </aside>

                <section
                    aria-label={t('screens.designer.grid')}
                    className="flex min-w-0 flex-1 flex-col gap-3.5 bg-rail p-6"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-3.5 text-ink-2">
                            <span>
                                {t(
                                    'screens.designer.count',
                                    {},
                                    screen.widgets.length,
                                )}
                            </span>
                            <label className="flex items-center gap-1.5">
                                {t('screens.designer.cols')}
                                <Select
                                    value={screen.cols}
                                    onChange={(e) =>
                                        setGrid(
                                            Number(e.target.value),
                                            screen.rows,
                                        )
                                    }
                                >
                                    {COLS.filter(
                                        (c) =>
                                            c >= props.limits.min_cols &&
                                            c <= props.limits.max_cols,
                                    ).map((c) => (
                                        <option key={c} value={c}>
                                            {c}
                                        </option>
                                    ))}
                                </Select>
                            </label>
                            <label className="flex items-center gap-1.5">
                                {t('screens.designer.rows')}
                                <Select
                                    value={screen.rows}
                                    onChange={(e) =>
                                        setGrid(
                                            screen.cols,
                                            Number(e.target.value),
                                        )
                                    }
                                >
                                    {Array.from(
                                        {
                                            length:
                                                props.limits.max_rows -
                                                props.limits.min_rows +
                                                1,
                                        },
                                        (_, i) => props.limits.min_rows + i,
                                    ).map((r) => (
                                        <option key={r} value={r}>
                                            {r}
                                        </option>
                                    ))}
                                </Select>
                            </label>
                            <label className="flex items-center gap-1.5">
                                {t('screens.designer.theme')}
                                <Select
                                    value={screen.theme}
                                    onChange={(e) =>
                                        change((s) => ({
                                            ...s,
                                            theme: e.target
                                                .value as Screen['theme'],
                                        }))
                                    }
                                >
                                    {(
                                        ['dark', 'light', 'schedule'] as const
                                    ).map((th) => (
                                        <option key={th} value={th}>
                                            {t(`screens.theme.${th}`)}
                                        </option>
                                    ))}
                                </Select>
                            </label>
                            {screen.theme === 'schedule' && (
                                <>
                                    <label className="flex items-center gap-1.5">
                                        {t('screens.designer.light_from')}
                                        <Input
                                            type="time"
                                            value={screen.light_from}
                                            onChange={(e) =>
                                                e.target.value &&
                                                change((s) => ({
                                                    ...s,
                                                    light_from: e.target.value,
                                                }))
                                            }
                                        />
                                    </label>
                                    <label className="flex items-center gap-1.5">
                                        {t('screens.designer.light_to')}
                                        <Input
                                            type="time"
                                            value={screen.light_to}
                                            onChange={(e) =>
                                                e.target.value &&
                                                change((s) => ({
                                                    ...s,
                                                    light_to: e.target.value,
                                                }))
                                            }
                                        />
                                    </label>
                                    <span className="text-xs text-ink-3">
                                        {t('screens.designer.now_theme', {
                                            theme: t(`screens.theme.${theme}`),
                                        })}
                                    </span>
                                </>
                            )}
                        </div>
                        <div
                            role="group"
                            aria-label={t('screens.designer.preview')}
                            className="flex gap-1.5"
                        >
                            <button
                                type="button"
                                aria-pressed={!incident}
                                onClick={() => setIncident(false)}
                                className={buttonClass(
                                    incident ? 'ghost' : 'default',
                                )}
                            >
                                {t('screens.designer.live_data')}
                            </button>
                            <button
                                type="button"
                                aria-pressed={incident}
                                onClick={() => setIncident(true)}
                                className={buttonClass(
                                    incident ? 'default' : 'ghost',
                                )}
                            >
                                {t('screens.designer.incident')}
                            </button>
                        </div>
                    </div>

                    {hiddenCount > 0 && (
                        <div
                            role="status"
                            className="rounded-lg border border-warn-line bg-warn-bg px-3 py-2 text-warn"
                        >
                            {t(
                                'screens.designer.overflow',
                                { cols: screen.cols, rows: screen.rows },
                                hiddenCount,
                            )}
                        </div>
                    )}

                    <div
                        ref={grid}
                        className="lj-screen lj-grid grid aspect-video gap-[10px] overflow-hidden rounded-[10px] border border-(--s-line) p-[14px]"
                        data-theme={theme}
                        onDragOver={allowDrop('end')}
                        onDrop={onDrop()}
                        onClick={(e) => {
                            if (e.target === e.currentTarget) {
                                setSelected(null);
                            }
                        }}
                        style={{
                            ['--lj-cols' as string]: screen.cols,
                            ['--lj-rows' as string]: screen.rows,
                            gridTemplateColumns: `repeat(${screen.cols}, minmax(0, 1fr))`,
                            gridTemplateRows: `repeat(${screen.rows}, minmax(0, 1fr))`,
                            gridAutoRows: 'minmax(0, 1fr)',
                        }}
                    >
                        {screen.widgets.map((widget, i) => (
                            <WidgetTile
                                key={widget.id}
                                data-widget={widget.id}
                                widget={widget}
                                schema={schema}
                                sources={sources}
                                data={data[widget.id]}
                                now={now}
                                paused={widget.id === selected}
                                incident={incident}
                                designer
                                tabIndex={0}
                                role="button"
                                aria-pressed={widget.id === selected}
                                aria-label={t(
                                    widget.title || widget.source
                                        ? 'screens.designer.tile'
                                        : 'screens.designer.tile_untitled',
                                    {
                                        kind: t(`screens.kinds.${widget.type}`),
                                        title:
                                            widget.title ??
                                            sources[widget.type]?.find(
                                                (s) => s.id === widget.source,
                                            )?.label ??
                                            '',
                                        w: widget.w,
                                        h: widget.h,
                                    },
                                )}
                                data-selected={widget.id === selected}
                                data-drop={dropTarget === widget.id}
                                draggable
                                className={cn(
                                    'cursor-move',
                                    !place[i]?.fits && 'opacity-40',
                                )}
                                onClick={() => setSelected(widget.id)}
                                onKeyDown={onKeyDown(widget, i)}
                                onDragStart={(e) => {
                                    if (resizing.current) {
                                        e.preventDefault();

                                        return;
                                    }

                                    e.dataTransfer.setData(
                                        'text/plain',
                                        widget.id,
                                    );
                                    e.dataTransfer.effectAllowed = 'move';
                                    drag.current = { tile: widget.id };
                                }}
                                onDragEnd={() => {
                                    drag.current = null;
                                    setDropTarget(null);
                                }}
                                onDragOver={allowDrop(widget.id)}
                                onDrop={onDrop(i)}
                                decoration={
                                    <>
                                        <span
                                            aria-hidden="true"
                                            className="lj-badge pointer-events-none absolute bottom-1.5 left-2 rounded border border-(--s-line) bg-(--s-ground) px-1.5 text-[10px] font-semibold tracking-wide text-(--s-ink-2) uppercase"
                                        >
                                            {`${t(`screens.kinds.${widget.type}`)} · ${widget.w}×${widget.h}`}
                                        </span>
                                        <span
                                            aria-hidden="true"
                                            draggable={false}
                                            onPointerDown={onResizeStart(
                                                widget,
                                            )}
                                            onPointerMove={onResizeMove}
                                            onPointerUp={() => {
                                                resizing.current = null;
                                            }}
                                            className="absolute right-0 bottom-0 z-10 flex size-[18px] cursor-nwse-resize touch-none items-end justify-end p-[3px]"
                                        >
                                            <span
                                                className="size-[9px] border-r-2 border-b-2"
                                                style={{
                                                    borderColor:
                                                        widget.id === selected
                                                            ? 'var(--s-amber)'
                                                            : 'var(--s-ink-4)',
                                                }}
                                            />
                                        </span>
                                    </>
                                }
                            />
                        ))}
                    </div>
                    <div
                        onDragOver={allowDrop('end')}
                        onDrop={onDrop()}
                        className={cn(
                            'flex h-11 items-center justify-center rounded-lg border border-dashed text-ink-3',
                            dropTarget === 'end'
                                ? 'border-amber'
                                : 'border-line-strong',
                        )}
                    >
                        {t('screens.designer.drop_end')}
                    </div>
                    <p className="text-xs text-ink-3">
                        {t('screens.designer.help')}
                    </p>
                </section>

                <aside
                    aria-label={t('screens.inspector.label')}
                    className="flex w-full flex-col gap-4 border-line bg-panel p-5 lg:w-[340px] lg:flex-none lg:border-l"
                >
                    {current ? (
                        <WidgetInspector
                            key={current.id}
                            widget={current}
                            catalog={catalog}
                            cols={screen.cols}
                            onChange={(widget) => patch(widget.id, widget)}
                            onRemove={() => remove(current.id)}
                        />
                    ) : (
                        <ScreenSettings
                            screen={screen}
                            catalog={catalog}
                            onChange={(changes) =>
                                change((s) => ({ ...s, ...changes }))
                            }
                        />
                    )}
                </aside>
            </div>
        </div>
    );
}
