import { Head, Link, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';
import type {
    DragEvent,
    KeyboardEvent,
    MouseEvent as ReactMouseEvent,
    PointerEvent,
} from 'react';
import { RectangleHorizontal, RectangleVertical } from 'lucide-react';
import { toast } from 'sonner';
import { useNow } from '@/components/lj/relative';
import {
    KioskFrame,
    KioskStatus,
    PreviewScale,
    kioskClock,
    kioskSubtitle,
} from '@/components/lj/screen/frame';
import {
    ScreenSettings,
    DisplayGear,
    WidgetInspector,
} from '@/components/lj/screen/inspector';
import type { Catalog } from '@/components/lj/screen/inspector';
import {
    canPlace,
    swapGroup,
    swapTarget,
    firstFree,
    fits,
    newWidgetId,
    regrid,
    themeAt,
} from '@/components/lj/screen/lib';
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
    exportMethod as exportRoute,
    index as screensIndex,
    layout as layoutRoute,
    reload as reloadRoute,
    show as showRoute,
    update as updateRoute,
    viewers as viewersRoute,
    widget as widgetRoute,
} from '@/routes/screens';

type Props = {
    screen: Screen;
    team: { name: string };
    /** The sizes the TVs showing this screen report, to preview it at. */
    displays: { name: string; w: number; h: number }[];
    /** Where a TV of the team says it reported a new size. */
    displays_channel: string;
    projects: { uuid: string; name: string }[];
    data_url: string;
    schema: Schema;
    sources: Sources;
    environments: string[];
    checks: Catalog['checks'];
    data_sources: Catalog['data_sources'];
    filters?: Catalog['filters'];
    limits: {
        min_cols: number;
        max_cols: number;
        min_rows: number;
        max_rows: number;
        max_widgets: number;
    };
    sharing: Sharing;
    tv: TvInstructionsProps;
    /** The display shapes a screen can be designed for (ScreenAspect), landscape. */
    aspects: string[];
    /** Ready layouts an empty screen can be filled from. */
    templates: {
        id: string;
        label: string;
        description: string;
        cols: number;
        rows: number;
        widgets: Widget[];
    }[];
};

// Long enough to finish a word or a nudge before it saves; leaving the page saves at once.
const SAVE_DELAY_MS = 1000;
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

/** Resize handles, invisible: which edges each moves, where it sits, and its cursor. */
type Handle = 'n' | 's' | 'e' | 'w' | 'ne' | 'nw' | 'se' | 'sw';

const HANDLES: { id: Handle; className: string }[] = [
    {
        id: 'n',
        className: 'inset-x-[18px] top-0 h-[8px] cursor-ns-resize',
    },
    {
        id: 's',
        className: 'inset-x-[18px] bottom-0 h-[8px] cursor-ns-resize',
    },
    {
        id: 'w',
        className: 'inset-y-[18px] left-0 w-[8px] cursor-ew-resize',
    },
    {
        id: 'e',
        className: 'inset-y-[18px] right-0 w-[8px] cursor-ew-resize',
    },
    {
        id: 'nw',
        className: 'top-0 left-0 size-[18px] cursor-nwse-resize',
    },
    {
        id: 'ne',
        className: 'top-0 right-0 size-[18px] cursor-nesw-resize',
    },
    {
        id: 'sw',
        className: 'bottom-0 left-0 size-[18px] cursor-nesw-resize',
    },
    {
        id: 'se',
        className: 'right-0 bottom-0 size-[18px] cursor-nwse-resize',
    },
];

/**
 * The box a handle drags to, moved by whole cells: the right and bottom edges change the
 * size, the left and top edges move x or y the other way round, never below one cell.
 */
function resized(
    box: { x: number; y: number; w: number; h: number },
    handle: Handle,
    dc: number,
    dr: number,
) {
    const next = { ...box };

    if (handle.includes('e')) {
        next.w = Math.max(1, box.w + dc);
    }

    if (handle.includes('w')) {
        const d = Math.min(dc, box.w - 1);
        next.x = box.x + d;
        next.w = box.w - d;
    }

    if (handle.includes('s')) {
        next.h = Math.max(1, box.h + dr);
    }

    if (handle.includes('n')) {
        const d = Math.min(dr, box.h - 1);
        next.y = box.y + d;
        next.h = box.h - d;
    }

    return next;
}

type Settings = Pick<
    Screen,
    | 'name'
    | 'aspect'
    | 'orientation'
    | 'fit'
    | 'show_header'
    | 'show_clock'
    | 'theme'
    | 'light_from'
    | 'light_to'
    | 'timezone'
    | 'scope'
>;

/** Resolutions to preview a screen at, per aspect, landscape. */
const PRESETS: Record<string, [number, number][]> = {
    '16:9': [
        [1280, 720],
        [1920, 1080],
        [2560, 1440],
        [3840, 2160],
    ],
    '16:10': [
        [1920, 1200],
        [2560, 1600],
    ],
    '4:3': [
        [1024, 768],
        [1600, 1200],
    ],
    '21:9': [[2560, 1080]],
    '43:18': [[3440, 1440]],
    '32:9': [
        [3840, 1080],
        [5120, 1440],
    ],
};

/** The designer's preview: the grid as large as fits, or the TV's layout at a size. */
type Preview =
    | { mode: 'fit' }
    | { mode: 'size'; w: number; h: number; custom?: boolean };

/** The preview this browser last used for a screen; a view setting, not the screen's. */
function storedPreview(uuid: string): Preview {
    try {
        const stored = JSON.parse(
            localStorage.getItem(`lj-screen-preview:${uuid}`) ?? 'null',
        ) as Preview | null;

        return stored?.mode === 'size' && stored.w > 0 && stored.h > 0
            ? stored
            : { mode: 'fit' };
    } catch {
        return { mode: 'fit' };
    }
}

const SETTINGS: (keyof Settings)[] = [
    'name',
    'aspect',
    'orientation',
    'fit',
    'show_header',
    'show_clock',
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
 * Only one widget's settings differ, on the same cells: that widget alone is
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
        old.x === changed[0].x &&
        old.y === changed[0].y &&
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
    // A TV resized or came back: its size in Preview at follows.
    useEcho(props.displays_channel, '.display.updated', () =>
        router.reload({ only: ['displays'] }),
    );
    const now = useNow();
    const [screen, setScreen] = useState<Screen>(props.screen);
    const [saved, setSaved] = useState<Screen>(props.screen);
    const [preview, setPreviewState] = useState<Preview>(() =>
        storedPreview(props.screen.uuid),
    );
    const setPreview = (next: Preview) => {
        setPreviewState(next);

        try {
            localStorage.setItem(
                `lj-screen-preview:${props.screen.uuid}`,
                JSON.stringify(next),
            );
        } catch {
            // Private window: it just does not stick.
        }
    };
    // Nothing is selected on load: the inspector opens on the screen's own settings.
    const [selected, setSelected] = useState<string | null>(null);
    // The value of the selected widget's rotation its inspector has open; the tile shows it.
    const [tab, setTab] = useState({ id: '', index: 0 });
    const pinned = tab.id === selected ? tab.index : 0;
    const [status, setStatus] = useState<'saved' | 'saving' | 'error'>('saved');
    const [viewers, setViewers] = useState<number | null>(null);
    const [incident, setIncident] = useState(false);
    const [query, setQuery] = useState('');
    const [closed, setClosed] = useState<string[]>([]);
    const [dialog, setDialog] = useState<'tv' | 'share' | null>(null);
    // What is dragged: a palette kind or a widget, its size, and which of its cells is held.
    const drag = useRef<{
        kind?: WidgetKind;
        tile?: string;
        w: number;
        h: number;
        dx: number;
        dy: number;
    } | null>(null);
    const [dragging, setDragging] = useState(false);
    // Where the dragged widget would land, and whether it may.
    const [hover, setHover] = useState<{
        x: number;
        y: number;
        w: number;
        h: number;
        ok: boolean;
        /** Dropping here moves these widgets to the dragged one's old cells. */
        swap?: Record<string, { x: number; y: number }>;
    } | null>(null);
    const resizing = useRef<{
        id: string;
        handle: Handle;
        pointerX: number;
        pointerY: number;
        box: { x: number; y: number; w: number; h: number };
        col: number;
        row: number;
    } | null>(null);
    const grid = useRef<HTMLElement>(null);
    const focusAfter = useRef<string | null>(null);
    const timer = useRef<number | null>(null);
    const savedRef = useRef<Screen>(props.screen);
    const latest = useRef<Screen>(props.screen);
    // One save at a time: a later one diffs against what the earlier one stored.
    const inFlight = useRef(false);
    const { schema, sources } = props;
    const catalog: Catalog = {
        schema,
        sources,
        projects: props.projects,
        environments: props.environments,
        checks: props.checks,
        data_sources: props.data_sources,
        screen: props.screen.uuid,
        periods: PERIODS,
        filters: props.filters,
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

        if (inFlight.current) {
            return;
        }

        inFlight.current = true;
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
            inFlight.current = false;

            if (latest.current === after) {
                setStatus('saved');
            } else if (timer.current === null) {
                // Changed while this save was under way: store that too.
                void save();
            }
        } catch (error) {
            inFlight.current = false;
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

    const starterSize = (kind: WidgetKind) => ({
        w: Math.min(STARTERS[kind].w, screen.cols),
        h: Math.min(STARTERS[kind].h, screen.rows),
    });

    /** A new widget at the cells given, else in the first free spot (1 x 1 if need be). */
    const add = (kind: WidgetKind, at?: { x: number; y: number }) => {
        if (screen.widgets.length >= props.limits.max_widgets) {
            toast.error(t('screens.designer.too_many'));

            return;
        }

        let size = starterSize(kind);
        let spot =
            at ??
            firstFree(screen.widgets, screen.cols, screen.rows, size.w, size.h);

        if (!spot) {
            size = { w: 1, h: 1 };
            spot = firstFree(screen.widgets, screen.cols, screen.rows, 1, 1);
        }

        if (!spot) {
            toast.error(t('screens.designer.no_room'));

            return;
        }

        const widget: Widget = {
            id: newWidgetId(),
            type: kind,
            source: STARTERS[kind].source,
            rotate: [],
            ...spot,
            ...size,
            title: null,
            options: {},
            scope: {},
        };
        setWidgets((widgets) => [...widgets, widget]);
        setSelected(widget.id);
    };

    /** Whether the widget may take that box; the screen's other widgets stay put. */
    const placeable = (
        id: string | undefined,
        box: { x: number; y: number; w: number; h: number },
    ) => canPlace(latest.current.widgets, screen.cols, screen.rows, box, id);

    const remove = (id: string) => {
        setWidgets((widgets) => widgets.filter((w) => w.id !== id));
        setSelected(null);
    };

    const setGrid = (cols: number, rows: number) =>
        change((s) => ({
            ...s,
            cols,
            rows,
            widgets: regrid(s.widgets, cols, rows),
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

    // Escape anywhere in the designer goes back to the screen settings, unless it is
    // closing something else first (a popover, a dialog, an open select).
    useEffect(() => {
        const onEscape = (e: globalThis.KeyboardEvent) => {
            if (
                e.key !== 'Escape' ||
                e.defaultPrevented ||
                document.querySelector(
                    ':popover-open, dialog[open], [role="dialog"][data-state="open"]',
                )
            ) {
                return;
            }

            setSelected(null);
        };

        window.addEventListener('keydown', onEscape);

        return () => window.removeEventListener('keydown', onEscape);
    }, []);

    const onKeyDown = (widget: Widget) => (e: KeyboardEvent) => {
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

        const dx = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        const dy = e.key === 'ArrowDown' ? 1 : e.key === 'ArrowUp' ? -1 : 0;
        // Shift and arrows resize, arrows move; either only into free cells.
        const box = e.shiftKey
            ? {
                  x: widget.x,
                  y: widget.y,
                  w: Math.max(1, widget.w + dx),
                  h: Math.max(1, widget.h + dy),
              }
            : { x: widget.x + dx, y: widget.y + dy, w: widget.w, h: widget.h };

        if (placeable(widget.id, box)) {
            patch(widget.id, box);
        }
    };

    const onResizeStart =
        (widget: Widget, handle: Handle) =>
        (e: PointerEvent<HTMLSpanElement>) => {
            e.preventDefault();
            e.stopPropagation();
            if (!grid.current) {
                return;
            }

            e.currentTarget.setPointerCapture(e.pointerId);
            const { rect, gap, pad } = gridMetrics();
            resizing.current = {
                id: widget.id,
                handle,
                pointerX: e.clientX,
                pointerY: e.clientY,
                box: { x: widget.x, y: widget.y, w: widget.w, h: widget.h },
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

        const current = latest.current.widgets.find((x) => x.id === r.id);

        if (!current) {
            return;
        }

        const box = resized(
            r.box,
            r.handle,
            Math.round((e.clientX - r.pointerX) / r.col),
            Math.round((e.clientY - r.pointerY) / r.row),
        );
        const ok = placeable(r.id, box);
        // The footprint shows what is asked; the tile follows only onto free cells.
        setHover({ ...box, ok });

        if (
            ok &&
            (current.x !== box.x ||
                current.y !== box.y ||
                current.w !== box.w ||
                current.h !== box.h)
        ) {
            patch(r.id, box);
        }
    };

    const onResizeEnd = () => {
        resizing.current = null;
        setHover(null);
    };

    /** The grid cell under a point, clamped to the grid. */
    // The grid's box, gap and padding on screen, whatever scale a preview draws it at.
    const gridMetrics = () => {
        const el = grid.current!;
        const rect = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        const scale = el.offsetWidth > 0 ? rect.width / el.offsetWidth : 1;

        return {
            rect,
            gap: parseFloat(style.columnGap) * scale || 0,
            pad: parseFloat(style.paddingLeft) * scale || 0,
        };
    };

    const cellAt = (clientX: number, clientY: number) => {
        const { rect, gap, pad } = gridMetrics();
        const col = (rect.width - 2 * pad + gap) / screen.cols;
        const row = (rect.height - 2 * pad + gap) / screen.rows;

        return {
            x: Math.max(
                0,
                Math.min(
                    screen.cols - 1,
                    Math.floor((clientX - rect.left - pad) / col),
                ),
            ),
            y: Math.max(
                0,
                Math.min(
                    screen.rows - 1,
                    Math.floor((clientY - rect.top - pad) / row),
                ),
            ),
        };
    };

    const startDrag = (d: NonNullable<typeof drag.current>) => {
        drag.current = d;
        // After the browser has taken its drag image: changing the page right away ends the drag.
        window.setTimeout(() => setDragging(true));
    };

    const endDrag = () => {
        drag.current = null;
        setDragging(false);
        setHover(null);
    };

    const onGridDragOver = (e: DragEvent) => {
        const d = drag.current;

        if (!d) {
            return;
        }

        const cell = cellAt(e.clientX, e.clientY);
        let box = { x: cell.x - d.dx, y: cell.y - d.dy, w: d.w, h: d.h };
        let ok = placeable(d.tile, box);
        let swap: Record<string, { x: number; y: number }> | undefined;
        const moving = d.tile
            ? latest.current.widgets.find((w) => w.id === d.tile)
            : undefined;

        // Onto other widgets: swap places when they fit, the footprint shows where it goes.
        if (!ok && moving) {
            // Whole widgets under the footprint trade places with it as a group.
            const group = swapGroup(
                latest.current.widgets,
                screen.cols,
                screen.rows,
                moving,
                box,
            );
            // Otherwise the one widget pointed at, each taking the other's top left.
            const other = group
                ? null
                : swapTarget(
                      latest.current.widgets,
                      screen.cols,
                      screen.rows,
                      moving,
                      cell,
                  );
            const target = latest.current.widgets.find((w) => w.id === other);

            if (group) {
                swap = group;
                ok = true;
            } else if (target) {
                swap = { [target.id]: { x: moving.x, y: moving.y } };
                ok = true;
                box = { x: target.x, y: target.y, w: moving.w, h: moving.h };
            }
        }

        if (ok) {
            e.preventDefault();
            e.dataTransfer.dropEffect = d.kind ? 'copy' : 'move';
        }

        if (
            hover?.x !== box.x ||
            hover?.y !== box.y ||
            hover?.w !== box.w ||
            hover?.ok !== ok ||
            JSON.stringify(hover?.swap) !== JSON.stringify(swap)
        ) {
            setHover({ ...box, ok, swap });
        }
    };

    const onGridDrop = (e: DragEvent) => {
        const d = drag.current;
        e.preventDefault();
        endDrag();

        if (!d || !hover?.ok) {
            return;
        }

        if (d.kind) {
            add(d.kind, { x: hover.x, y: hover.y });
        } else if (d.tile && hover.swap) {
            const moves = hover.swap;
            const to = { x: hover.x, y: hover.y };

            setWidgets((widgets) =>
                widgets.map((w) =>
                    w.id === d.tile
                        ? { ...w, ...to }
                        : moves[w.id]
                          ? { ...w, ...moves[w.id] }
                          : w,
                ),
            );
            setSelected(d.tile);
        } else if (d.tile) {
            patch(d.tile, { x: hover.x, y: hover.y });
            setSelected(d.tile);
        }
    };

    const q = query.trim().toLowerCase();
    const groups = [
        { id: 'data', kinds: DATA_KINDS },
        // An empty cell is not offered any more: a free cell simply stays empty.
        { id: 'layout', kinds: LAYOUT_KINDS.filter((k) => k !== 'empty') },
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

    const inGrid = (w: Widget) => w.x < screen.cols && w.y < screen.rows;
    // Widgets left outside a smaller grid: kept, not on the TV, dragged back from the list.
    const outside = screen.widgets.filter(
        (w) => !fits(w, screen.cols, screen.rows),
    );
    // Top left to bottom right, so Tab follows the screen.
    const tiles = screen.widgets
        .filter(inGrid)
        .sort((a, b) => a.y - b.y || a.x - b.x);
    const current = screen.widgets.find((w) => w.id === selected) ?? null;
    const theme = themeAt(screen, now);
    const [aw, ah] = screen.aspect.split(':').map(Number);
    const ratio = screen.orientation === 'portrait' ? [ah, aw] : [aw, ah];
    const turn = ([w, h]: number[]) =>
        screen.orientation === 'portrait' ? [h, w] : [w, h];
    const presets = (PRESETS[screen.aspect] ?? []).map(turn);
    const previewValue =
        preview.mode === 'fit'
            ? 'fit'
            : preview.custom
              ? 'custom'
              : `${preview.w}x${preview.h}`;
    const previewSize =
        preview.mode === 'size' ? { w: preview.w, h: preview.h } : null;
    const statusText =
        status === 'saving'
            ? t('screens.designer.saving')
            : status === 'error'
              ? t('screens.designer.error')
              : viewers !== null
                ? t('screens.designer.saved_live', {}, viewers)
                : t('screens.designer.saved');

    // What the grid holds and does, the same at Fit and at a previewed size.
    const gridHandlers = {
        onDragOver: onGridDragOver,
        onDrop: onGridDrop,
        onDragLeave: (e: DragEvent<HTMLElement>) => {
            if (!e.currentTarget.contains(e.relatedTarget as Node | null)) {
                setHover(null);
            }
        },
        onClick: (e: ReactMouseEvent<HTMLElement>) => {
            if (e.target === e.currentTarget) {
                setSelected(null);
            }
        },
    };
    const gridContent = (
        <>
            {/* While dragging, every cell is a drop zone. */}
            {dragging &&
                Array.from({ length: screen.cols * screen.rows }, (_, i) => (
                    <span
                        key={`cell-${i}`}
                        aria-hidden="true"
                        className="pointer-events-none rounded-[8px] border border-dashed border-(--s-line-strong)"
                        style={{
                            gridColumn: (i % screen.cols) + 1,
                            gridRow: Math.floor(i / screen.cols) + 1,
                        }}
                    />
                ))}
            {screen.widgets.length === 0 && !dragging && (
                <div
                    className="flex flex-col items-center justify-center gap-[1em] rounded-[8px] border border-dashed border-(--s-line-strong) p-[2em] text-center"
                    style={{
                        gridColumn: '1 / -1',
                        gridRow: '1 / -1',
                    }}
                >
                    <span className="text-[1.1em] font-semibold text-(--s-ink)">
                        {t('screens.designer.empty.title')}
                    </span>
                    <span className="max-w-[36em] text-(--s-ink-3)">
                        {t('screens.designer.empty.body')}
                    </span>
                    <div className="flex flex-wrap justify-center gap-[0.5em]">
                        {props.templates.map((template) => (
                            <button
                                key={template.id}
                                type="button"
                                title={template.description}
                                className="cursor-pointer rounded-[6px] border border-(--s-line-strong) bg-(--s-panel) px-[0.9em] py-[0.45em] text-(--s-ink-2) hover:border-(--s-amber) hover:text-(--s-ink)"
                                onClick={() =>
                                    change((s) => ({
                                        ...s,
                                        cols: template.cols,
                                        rows: template.rows,
                                        widgets: template.widgets.map((w) => ({
                                            ...w,
                                            id: newWidgetId(),
                                        })),
                                    }))
                                }
                            >
                                {t('screens.designer.empty.use', {
                                    template: template.label,
                                })}
                            </button>
                        ))}
                    </div>
                </div>
            )}
            {tiles.map((widget) => (
                <WidgetTile
                    key={widget.id}
                    data-widget={widget.id}
                    widget={widget}
                    schema={schema}
                    sources={sources}
                    data={data[widget.id]}
                    now={now}
                    timezone={screen.timezone}
                    pinned={widget.id === selected ? pinned : undefined}
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
                    draggable
                    className={cn(
                        'cursor-move',
                        (!fits(widget, screen.cols, screen.rows) ||
                            (dragging && drag.current?.tile === widget.id)) &&
                            'opacity-40',
                    )}
                    // A widget partly outside the grid shows the part inside it.
                    style={
                        fits(widget, screen.cols, screen.rows)
                            ? undefined
                            : {
                                  gridColumn: `${widget.x + 1} / ${Math.min(widget.x + widget.w, screen.cols) + 1}`,
                                  gridRow: `${widget.y + 1} / ${Math.min(widget.y + widget.h, screen.rows) + 1}`,
                              }
                    }
                    onClick={() => setSelected(widget.id)}
                    onKeyDown={onKeyDown(widget)}
                    onDragStart={(e) => {
                        if (resizing.current) {
                            e.preventDefault();

                            return;
                        }

                        e.dataTransfer.setData('text/plain', widget.id);
                        e.dataTransfer.effectAllowed = 'move';
                        // Held by the cell under the pointer, so it lands where it was dragged.
                        const cell = cellAt(e.clientX, e.clientY);
                        startDrag({
                            tile: widget.id,
                            w: widget.w,
                            h: widget.h,
                            dx: Math.max(
                                0,
                                Math.min(widget.w - 1, cell.x - widget.x),
                            ),
                            dy: Math.max(
                                0,
                                Math.min(widget.h - 1, cell.y - widget.y),
                            ),
                        });
                    }}
                    onDragEnd={endDrag}
                    corner={
                        widget.type !== 'empty' && (
                            <DisplayGear
                                widget={widget}
                                catalog={catalog}
                                onChange={(next) => patch(next.id, next)}
                                onOpen={() => setSelected(widget.id)}
                            />
                        )
                    }
                    decoration={
                        <>
                            <span
                                aria-hidden="true"
                                className="lj-badge pointer-events-none absolute bottom-1.5 left-2 rounded border border-(--s-line) bg-(--s-ground) px-1.5 text-[10px] font-semibold tracking-wide text-(--s-ink-2) uppercase"
                            >
                                {`${t(`screens.kinds.${widget.type}`)} · ${widget.w}×${widget.h}`}
                            </span>
                            {HANDLES.map((handle) => (
                                <span
                                    key={handle.id}
                                    aria-hidden="true"
                                    draggable={false}
                                    data-handle={handle.id}
                                    onPointerDown={onResizeStart(
                                        widget,
                                        handle.id,
                                    )}
                                    onPointerMove={onResizeMove}
                                    onPointerUp={onResizeEnd}
                                    onPointerCancel={onResizeEnd}
                                    className={cn(
                                        'absolute z-10 touch-none',
                                        handle.className,
                                    )}
                                />
                            ))}
                        </>
                    }
                />
            ))}
            {hover && (
                <span
                    aria-hidden="true"
                    data-testid="drop-footprint"
                    data-ok={hover.ok}
                    className={cn(
                        'pointer-events-none z-20 rounded-[8px] border-2',
                        hover.ok
                            ? 'border-(--s-green) bg-(--s-green)/20'
                            : 'border-(--s-red) bg-(--s-red)/20',
                    )}
                    style={{
                        gridColumn: `${Math.max(0, hover.x) + 1} / ${Math.min(hover.x + hover.w, screen.cols) + 1}`,
                        gridRow: `${Math.max(0, hover.y) + 1} / ${Math.min(hover.y + hover.h, screen.rows) + 1}`,
                    }}
                />
            )}
        </>
    );

    return (
        <div className="flex min-h-screen flex-col bg-ground text-[13px] text-ink lg:h-screen">
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
                    <Button
                        title={t('screens.designer.reload_hint')}
                        onClick={async () => {
                            const response = await fetch(
                                reloadRoute(screen.uuid).url,
                                {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        Accept: 'application/json',
                                        'X-XSRF-TOKEN': xsrf(),
                                    },
                                },
                            ).catch(() => null);
                            const json = (await response
                                ?.json()
                                .catch(() => null)) as {
                                message?: string;
                            } | null;
                            const message =
                                json?.message ??
                                t('screens.designer.reload_failed');

                            if (response?.ok) {
                                toast.success(message);
                            } else {
                                toast.error(message);
                            }
                        }}
                    >
                        {t('screens.designer.reload')}
                    </Button>
                    <a
                        href={exportRoute(screen.uuid).url}
                        download
                        title={t('screens.actions.export_hint')}
                        className={buttonClass()}
                    >
                        {t('screens.actions.export')}
                    </a>
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

            {/* Wide screens: the top bar, then three columns that fill the rest and scroll on their own. */}
            <div className="flex min-h-0 flex-1 flex-wrap lg:flex-nowrap">
                <aside
                    aria-label={t('screens.palette.title')}
                    className="flex w-full flex-col gap-2 border-line p-5 lg:w-[240px] lg:flex-none lg:[scrollbar-gutter:stable] lg:overflow-y-auto lg:border-r"
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
                                                startDrag({
                                                    kind,
                                                    ...starterSize(kind),
                                                    dx: 0,
                                                    dy: 0,
                                                });
                                            }}
                                            onDragEnd={endDrag}
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
                    className="flex min-w-0 flex-1 flex-col bg-rail p-6 lg:overflow-y-auto"
                >
                    <div className="flex flex-col gap-3.5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex flex-wrap items-center gap-3.5 text-ink-2">
                                <Button
                                    aria-pressed={selected === null}
                                    onClick={() => setSelected(null)}
                                >
                                    {t('screens.settings.title')}
                                </Button>
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
                                    {t('screens.designer.aspect')}
                                    <Select
                                        value={screen.aspect}
                                        onChange={(e) =>
                                            change((s) => ({
                                                ...s,
                                                aspect: e.target.value,
                                            }))
                                        }
                                    >
                                        {props.aspects.map((a) => (
                                            <option key={a} value={a}>
                                                {t(`screens.aspect.${a}`)}
                                            </option>
                                        ))}
                                    </Select>
                                </label>
                                <div
                                    role="group"
                                    aria-label={t(
                                        'screens.designer.orientation',
                                    )}
                                    className="flex"
                                >
                                    {(['landscape', 'portrait'] as const).map(
                                        (o) => (
                                            <button
                                                key={o}
                                                type="button"
                                                aria-pressed={
                                                    screen.orientation === o
                                                }
                                                aria-label={t(
                                                    `screens.orientation.${o}`,
                                                )}
                                                title={t(
                                                    `screens.orientation.${o}`,
                                                )}
                                                className={cn(
                                                    'flex h-8 w-8 items-center justify-center border border-line first:rounded-l-md last:rounded-r-md',
                                                    screen.orientation === o
                                                        ? 'bg-amber text-amber-ink'
                                                        : 'bg-rail text-ink-2 hover:text-ink',
                                                )}
                                                onClick={() =>
                                                    change((s) => ({
                                                        ...s,
                                                        orientation: o,
                                                    }))
                                                }
                                            >
                                                {o === 'landscape' ? (
                                                    <RectangleHorizontal
                                                        aria-hidden="true"
                                                        className="size-4"
                                                    />
                                                ) : (
                                                    <RectangleVertical
                                                        aria-hidden="true"
                                                        className="size-4"
                                                    />
                                                )}
                                            </button>
                                        ),
                                    )}
                                </div>
                                <label className="flex items-center gap-1.5">
                                    {t('screens.designer.preview_at')}
                                    <Select
                                        value={previewValue}
                                        onChange={(e) => {
                                            const v = e.target.value;

                                            if (v === 'fit') {
                                                setPreview({ mode: 'fit' });
                                            } else if (v === 'custom') {
                                                setPreview({
                                                    mode: 'size',
                                                    w: previewSize?.w ?? 1920,
                                                    h: previewSize?.h ?? 1080,
                                                    custom: true,
                                                });
                                            } else {
                                                const [w, h] = v
                                                    .split('x')
                                                    .map(Number);
                                                setPreview({
                                                    mode: 'size',
                                                    w,
                                                    h,
                                                });
                                            }
                                        }}
                                    >
                                        <option value="fit">
                                            {t('screens.designer.preview_fit')}
                                        </option>
                                        {props.displays.length > 0 && (
                                            <optgroup
                                                label={t(
                                                    'screens.designer.preview_tvs',
                                                )}
                                            >
                                                {props.displays.map((d, i) => (
                                                    <option
                                                        key={`d${i}`}
                                                        value={`${d.w}x${d.h}`}
                                                    >
                                                        {`${d.name}, ${d.w} × ${d.h}`}
                                                    </option>
                                                ))}
                                            </optgroup>
                                        )}
                                        <optgroup
                                            label={t(
                                                'screens.designer.preview_common',
                                            )}
                                        >
                                            {presets.map(([w, h]) => (
                                                <option
                                                    key={`p${w}x${h}`}
                                                    value={`${w}x${h}`}
                                                >
                                                    {`${w} × ${h}`}
                                                </option>
                                            ))}
                                        </optgroup>
                                        {/* A size not offered keeps showing as itself. */}
                                        {preview.mode === 'size' &&
                                            !preview.custom &&
                                            ![
                                                ...presets,
                                                ...props.displays.map((d) => [
                                                    d.w,
                                                    d.h,
                                                ]),
                                            ].some(
                                                ([w, h]) =>
                                                    w === preview.w &&
                                                    h === preview.h,
                                            ) && (
                                                <option value={previewValue}>
                                                    {`${preview.w} × ${preview.h}`}
                                                </option>
                                            )}
                                        <option value="custom">
                                            {t(
                                                'screens.designer.preview_custom',
                                            )}
                                        </option>
                                    </Select>
                                </label>
                                {preview.mode === 'size' && preview.custom && (
                                    <span className="flex items-center gap-1">
                                        {(['w', 'h'] as const).map((key) => (
                                            <Input
                                                key={key}
                                                type="number"
                                                min={100}
                                                max={20000}
                                                aria-label={t(
                                                    `screens.designer.preview_${key}`,
                                                )}
                                                className="h-8 w-20"
                                                value={preview[key]}
                                                onChange={(e) => {
                                                    const n = Number(
                                                        e.target.value,
                                                    );

                                                    if (
                                                        n >= 100 &&
                                                        n <= 20000
                                                    ) {
                                                        setPreview({
                                                            ...preview,
                                                            [key]: n,
                                                        });
                                                    }
                                                }}
                                            />
                                        ))}
                                    </span>
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

                        {outside.length > 0 && (
                            <div
                                role="status"
                                className="flex flex-col gap-2 rounded-lg border border-warn-line bg-warn-bg px-3 py-2 text-warn"
                            >
                                {t(
                                    'screens.designer.overflow',
                                    { cols: screen.cols, rows: screen.rows },
                                    outside.length,
                                )}
                                <div className="flex flex-wrap gap-1.5">
                                    {outside.map((widget) => (
                                        <button
                                            key={widget.id}
                                            type="button"
                                            draggable
                                            aria-pressed={
                                                widget.id === selected
                                            }
                                            onClick={() =>
                                                setSelected(widget.id)
                                            }
                                            onDragStart={(e) => {
                                                e.dataTransfer.setData(
                                                    'text/plain',
                                                    widget.id,
                                                );
                                                e.dataTransfer.effectAllowed =
                                                    'move';
                                                startDrag({
                                                    tile: widget.id,
                                                    w: widget.w,
                                                    h: widget.h,
                                                    dx: 0,
                                                    dy: 0,
                                                });
                                            }}
                                            onDragEnd={endDrag}
                                            className={cn(
                                                'cursor-grab rounded-md border border-warn-line bg-panel px-2 py-1 text-xs text-ink',
                                                widget.id === selected &&
                                                    'border-amber',
                                            )}
                                        >
                                            {`${widget.title ?? sources[widget.type]?.find((x) => x.id === widget.source)?.label ?? t(`screens.kinds.${widget.type}`)} · ${widget.w}×${widget.h}`}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* The TV's own layout: at Fit as large as fits, else at the
                        previewed size scaled down to fit here. */}
                        <PreviewScale
                            size={previewSize}
                            ratio={ratio[0] / ratio[1]}
                        >
                            {(view) => (
                                <KioskFrame
                                    screen={screen}
                                    view={view}
                                    theme={theme}
                                    header={{
                                        title: screen.name,
                                        subtitle: kioskSubtitle(
                                            screen,
                                            props.team.name,
                                            props.projects,
                                            t,
                                        ),
                                        right: (
                                            <KioskStatus
                                                attention={0}
                                                offline={null}
                                                clock={
                                                    screen.show_clock
                                                        ? kioskClock(
                                                              screen,
                                                              now,
                                                          )
                                                        : null
                                                }
                                            />
                                        ),
                                    }}
                                    gridRef={grid}
                                    gridProps={{ ...gridHandlers }}
                                >
                                    {gridContent}
                                </KioskFrame>
                            )}
                        </PreviewScale>
                        <p className="text-xs text-ink-3">
                            {t('screens.designer.help')}
                        </p>
                    </div>
                </section>

                <aside
                    aria-label={t('screens.inspector.label')}
                    className="flex w-full flex-col gap-4 border-line bg-panel p-5 lg:w-[340px] lg:flex-none lg:[scrollbar-gutter:stable] lg:overflow-y-auto lg:border-l"
                >
                    {current ? (
                        <WidgetInspector
                            key={current.id}
                            widget={current}
                            catalog={catalog}
                            onChange={(widget) => patch(widget.id, widget)}
                            onRemove={() => remove(current.id)}
                            tab={pinned}
                            onTab={(index) => setTab({ id: current.id, index })}
                        />
                    ) : (
                        <ScreenSettings
                            screen={screen}
                            catalog={catalog}
                            theme={theme}
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
