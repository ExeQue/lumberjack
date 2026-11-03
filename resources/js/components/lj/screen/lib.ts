import {
    formatBytes,
    formatCompact,
    formatNumber,
    formatPercent,
} from '@/lib/format';
import type {
    RotateItem,
    Schema,
    SchemaField,
    Screen,
    Status,
    Widget,
    WidgetData,
    WidgetKind,
} from './types';
import { ROTATING_KINDS } from './types';

export function fieldsOf(
    schema: Schema,
    kind: WidgetKind,
): Record<string, SchemaField> {
    const fields: Record<string, SchemaField> = {};

    for (const section of schema[kind] ?? []) {
        if (section.id === 'scope') {
            continue;
        }

        for (const field of section.fields) {
            fields[field.key] = field;
        }
    }

    return fields;
}

/** A widget's option: what it stores, else the schema's default. */
export function option<T = unknown>(
    schema: Schema,
    widget: Widget,
    key: string,
): T {
    return (widget.options[key] ??
        fieldsOf(schema, widget.type)[key]?.default ??
        null) as T;
}

/**
 * What the widget's data depends on. Only a change to this refetches it: moving,
 * resizing, retitling or a display option leaves its data alone.
 */
export function dataKey(schema: Schema, widget: Widget): string {
    const fields = fieldsOf(schema, widget.type);
    const options = Object.keys(widget.options)
        .filter((key) => fields[key]?.data)
        .sort()
        .map((key) => [key, widget.options[key]]);

    // A rotated value's seconds and title only change how it draws.
    const rotation = rotationOf(widget).map((item) => [
        item.source,
        item.scope?.period ?? null,
        Object.keys(item.options ?? {})
            .filter((key) => fields[key]?.data)
            .sort()
            .map((key) => [key, item.options?.[key]]),
    ]);

    return JSON.stringify([widget.type, rotation, widget.scope, options]);
}

/**
 * What a tile shows in turn: its source first, with the widget's title and its
 * source_seconds, then the values it rotates through, each a RotateItem.
 */
export function rotationOf(widget: Widget): RotateItem[] {
    if (widget.source === null) {
        return [];
    }

    const seconds = widget.options.source_seconds;
    const items: RotateItem[] = [
        {
            source: widget.source,
            seconds: typeof seconds === 'number' ? seconds : undefined,
            title: widget.title ?? undefined,
        },
    ];

    if (ROTATING_KINDS.includes(widget.type)) {
        for (const entry of widget.rotate ?? []) {
            const item = typeof entry === 'string' ? { source: entry } : entry;

            if (!items.some((i) => i.source === item.source)) {
                items.push(item);
            }
        }
    }

    return items;
}

/** The sources a tile shows in turn: the picked one first, then those it rotates through. */
export function sourcesOf(widget: Widget): string[] {
    return rotationOf(widget).map((item) => item.source);
}

/** The option fields a rotated value can set for itself: its type's first section. */
export function itemFieldsOf(schema: Schema, kind: WidgetKind): SchemaField[] {
    return schema[kind]?.[0]?.fields ?? [];
}

/** The widget as it shows one value of its rotation: that value's settings over its own. */
export function shownAs(widget: Widget, item: RotateItem | undefined): Widget {
    if (!item || item.source === widget.source) {
        return widget;
    }

    return {
        ...widget,
        source: item.source,
        title: item.title ?? null,
        options: { ...widget.options, ...item.options },
        scope: { ...widget.scope, ...item.scope },
    };
}

type Payload = Record<string, unknown>;

type ListRow = { state?: string | null };

/** A tile's color from its data: see the plan's status colors. */
export function statusOf(
    kind: WidgetKind,
    data: Payload | null | undefined,
): Status {
    if (!data) {
        return null;
    }

    if (kind === 'stat' || kind === 'gauge') {
        return stateToStatus(data.state as string | null);
    }

    if (kind === 'list') {
        const states = ((data.rows as ListRow[]) ?? []).map((r) => r.state);

        return states.includes('red')
            ? 'down'
            : states.includes('amber')
              ? 'degraded'
              : null;
    }

    if (kind === 'health') {
        const check = data.check as { state: string } | null;

        if (check) {
            return checkToStatus(check.state);
        }

        const summary = data.summary as Record<string, number> | undefined;

        if (!summary || summary.total === 0) {
            return null;
        }

        // A down check turns the widget red; degraded or recovering only color their row.
        if (summary.down > 0) {
            return 'down';
        }

        return summary.ok === summary.total ? 'ok' : null;
    }

    return null;
}

export function stateToStatus(state: string | null | undefined): Status {
    return state === 'red'
        ? 'down'
        : state === 'amber'
          ? 'degraded'
          : state === 'ok'
            ? 'ok'
            : null;
}

export function checkToStatus(state: string | null | undefined): Status {
    return state === 'down' ||
        state === 'degraded' ||
        state === 'recovering' ||
        state === 'stale' ||
        state === 'ok'
        ? state
        : null;
}

export function needsAttention(status: Status): boolean {
    return status === 'down' || status === 'degraded';
}

/**
 * Which source a rotating tile shows at this moment, each for its own seconds. Every
 * tile and every kiosk counts from the same clock, so tiles with the same timing switch
 * together; one that needs attention holds.
 */
export function rotationIndex(
    kind: WidgetKind,
    sources: string[],
    data: WidgetData | undefined,
    seconds: number[],
    hold: boolean,
    now: number,
): number {
    if (sources.length < 2) {
        return 0;
    }

    if (hold) {
        const bad = sources.findIndex((id) =>
            needsAttention(statusOf(kind, data?.[id])),
        );

        if (bad >= 0) {
            return bad;
        }
    }

    // Each value shows for its own seconds; a cycle is their sum.
    const spans = sources.map((_, i) => Math.max(1, seconds[i] ?? 10));
    let at = (now / 1000) % spans.reduce((sum, s) => sum + s, 0);

    for (let i = 0; i < spans.length; i++) {
        if (at < spans[i]) {
            return i;
        }

        at -= spans[i];
    }

    return 0;
}

/** A value in its unit: counts compact, shares as percent, durations in ms or s, sizes in B to TB. */
export function formatValue(
    value: unknown,
    unit: string,
    decimals: string = 'auto',
): string {
    if (value === null || value === undefined) {
        return '-';
    }

    if (typeof value === 'string') {
        return value;
    }

    const n = Number(value);
    const fixed = decimals === 'auto' ? undefined : Number(decimals);

    switch (unit) {
        case 'ratio':
            return formatPercent(n, fixed ?? 1);
        case 'ms':
            return n >= 1000
                ? `${formatNumber(n / 1000, fixed ?? 1)} s`
                : `${formatNumber(n, fixed ?? 0)} ms`;
        case 'score':
            return formatNumber(n, fixed ?? 2);
        case 'bytes':
            return formatBytes(n, fixed);
        default:
            return fixed !== undefined
                ? formatNumber(n, fixed)
                : Math.abs(n) >= 10_000
                  ? formatCompact(n)
                  : formatNumber(n, 0);
    }
}

/** Minutes since midnight of now in a time zone. */
function minuteIn(timezone: string, now: number): number {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone,
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(now));
    const get = (type: string) =>
        Number(parts.find((p) => p.type === type)?.value ?? 0);

    return get('hour') * 60 + get('minute');
}

const minutes = (time: string): number => {
    const [h, m] = time.split(':').map(Number);

    return (h || 0) * 60 + (m || 0);
};

/**
 * Dark or light now; the same rule as Screen::currentTheme() on the server, kept here so
 * an open kiosk switches at the hour without asking.
 */
export function themeAt(
    screen: Pick<Screen, 'theme' | 'light_from' | 'light_to' | 'timezone'>,
    now: number,
): 'dark' | 'light' {
    if (screen.theme !== 'schedule') {
        return screen.theme;
    }

    let minute: number;

    try {
        minute = minuteIn(screen.timezone, now);
    } catch {
        minute = new Date(now).getHours() * 60 + new Date(now).getMinutes();
    }

    const from = minutes(screen.light_from);
    const to = minutes(screen.light_to);
    const light =
        from <= to
            ? minute >= from && minute < to
            : minute >= from || minute < to;

    return light ? 'light' : 'dark';
}

/**
 * The designer's "Show an incident" preview: the same data, pushed into trouble, so the
 * colors and holding rotation can be judged before it happens for real.
 */
export function incident(
    kind: WidgetKind,
    data: Payload | null | undefined,
): Payload | null | undefined {
    if (!data) {
        return data;
    }

    switch (kind) {
        case 'stat':
        case 'gauge': {
            const limits = data.limits as {
                amber: number | null;
                red: number | null;
            } | null;

            // Only a value with limits can be in trouble.
            return limits && (limits.amber !== null || limits.red !== null)
                ? { ...data, state: 'red' }
                : data;
        }
        case 'list': {
            const rows = (data.rows as ListRow[]) ?? [];

            return {
                ...data,
                rows: rows.map((row, i) =>
                    i === 0 ? { ...row, state: 'red' } : row,
                ),
            };
        }
        case 'health': {
            const checks = (data.checks as { state: string }[]) ?? [];
            const summary = data.summary as Record<string, number> | undefined;
            const check = data.check as Payload | null;

            return {
                ...data,
                checks: checks.map((c, i) =>
                    i === 0 ? { ...c, state: 'down' } : c,
                ),
                summary:
                    summary && checks.length > 0
                        ? {
                              ...summary,
                              down:
                                  summary.down +
                                  (checks[0].state === 'down' ? 0 : 1),
                              ok: Math.max(
                                  0,
                                  summary.ok -
                                      (checks[0].state === 'ok' ? 1 : 0),
                              ),
                          }
                        : summary,
                check: check ? { ...check, state: 'down' } : check,
            };
        }
        default:
            return data;
    }
}

/** A short random widget id. */
export function newWidgetId(): string {
    return 'w' + Math.random().toString(36).slice(2, 10);
}

type Box = Pick<Widget, 'x' | 'y' | 'w' | 'h'>;

/** Whether a widget lies inside the grid, so the TV shows it. */
export function fits(box: Box, cols: number, rows: number): boolean {
    return (
        box.x >= 0 &&
        box.y >= 0 &&
        box.x + box.w <= cols &&
        box.y + box.h <= rows
    );
}

/** Whether no widget but `except` covers a cell of the box, inside the grid or not. */
export function isFree(widgets: Widget[], box: Box, except?: string): boolean {
    return widgets.every(
        (o) =>
            o.id === except ||
            o.x >= box.x + box.w ||
            box.x >= o.x + o.w ||
            o.y >= box.y + box.h ||
            box.y >= o.y + o.h,
    );
}

/** Whether a widget can go there: inside the grid, on cells no other widget covers. */
export function canPlace(
    widgets: Widget[],
    cols: number,
    rows: number,
    box: Box,
    except?: string,
): boolean {
    return fits(box, cols, rows) && isFree(widgets, box, except);
}

/** The first free spot for a w x h widget, row by row, or null when there is none. */
export function firstFree(
    widgets: Widget[],
    cols: number,
    rows: number,
    w: number,
    h: number,
): { x: number; y: number } | null {
    for (let y = 0; y + h <= rows; y++) {
        for (let x = 0; x + w <= cols; x++) {
            if (isFree(widgets, { x, y, w, h })) {
                return { x, y };
            }
        }
    }

    return null;
}

/**
 * The widgets on a new grid size: none larger than the grid, those that still fit stay,
 * the others move just far enough back inside when those cells are free, or wait below
 * the grid (kept, not shown on the TV) until there is room again.
 */
export function regrid(
    widgets: Widget[],
    cols: number,
    rows: number,
): Widget[] {
    const result = widgets.map((w) => ({
        ...w,
        w: Math.min(w.w, cols),
        h: Math.min(w.h, rows),
    }));

    result.forEach((widget, i) => {
        if (fits(widget, cols, rows)) {
            return;
        }

        const clamped = {
            ...widget,
            x: Math.max(0, Math.min(widget.x, cols - widget.w)),
            y: Math.max(0, Math.min(widget.y, rows - widget.h)),
        };

        if (isFree(result, clamped, widget.id)) {
            result[i] = clamped;

            return;
        }

        // ponytail: scans down from the grid's last row, at most 48 widgets
        for (let y = rows; ; y++) {
            for (let x = 0; x + widget.w <= cols; x++) {
                if (
                    isFree(
                        result,
                        { x, y, w: widget.w, h: widget.h },
                        widget.id,
                    )
                ) {
                    result[i] = { ...widget, x, y };

                    return;
                }
            }
        }
    });

    return result;
}

/**
 * Dropping a widget onto another swaps them: each takes the other's top left cell,
 * keeping its own size. Returns the other widget's id when both then fit in the grid
 * without covering anything else, null otherwise.
 */
export function swapTarget(
    widgets: Widget[],
    cols: number,
    rows: number,
    moving: Widget,
    cell: { x: number; y: number },
): string | null {
    const other = widgets.find(
        (o) =>
            o.id !== moving.id &&
            cell.x >= o.x &&
            cell.x < o.x + o.w &&
            cell.y >= o.y &&
            cell.y < o.y + o.h,
    );

    if (!other) {
        return null;
    }

    const rest = widgets.filter((o) => o.id !== moving.id && o.id !== other.id);
    const movingThere = { x: other.x, y: other.y, w: moving.w, h: moving.h };
    const otherHere = { x: moving.x, y: moving.y, w: other.w, h: other.h };
    const apart = isFree([{ ...moving, ...movingThere }], otherHere);

    return canPlace(rest, cols, rows, movingThere) &&
        canPlace(rest, cols, rows, otherHere) &&
        apart
        ? other.id
        : null;
}

/**
 * Dropping a widget on cells other widgets fill entirely swaps it with all of them: the
 * group moves, as one, to where the widget was. A 2 x 2 onto two 2 x 1 stacked, say.
 * Returns the new top left of each moved widget, or null when that does not fit.
 */
export function swapGroup(
    widgets: Widget[],
    cols: number,
    rows: number,
    moving: Widget,
    box: Box,
): Record<string, { x: number; y: number }> | null {
    if (!fits(box, cols, rows)) {
        return null;
    }

    const covered = widgets.filter(
        (o) => o.id !== moving.id && !isFree([o], box),
    );
    const inside = (o: Widget) =>
        o.x >= box.x &&
        o.y >= box.y &&
        o.x + o.w <= box.x + box.w &&
        o.y + o.h <= box.y + box.h;

    if (covered.length === 0 || !covered.every(inside)) {
        return null;
    }

    const dx = moving.x - box.x;
    const dy = moving.y - box.y;
    const ids = new Set([moving.id, ...covered.map((o) => o.id)]);
    const rest = widgets.filter((o) => !ids.has(o.id));
    const moved = covered.map((o) => ({ ...o, x: o.x + dx, y: o.y + dy }));
    const ok = moved.every(
        (o) =>
            fits(o, cols, rows) &&
            isFree(rest, o) &&
            isFree([{ ...moving, ...box }], o),
    );

    return ok
        ? Object.fromEntries(moved.map((o) => [o.id, { x: o.x, y: o.y }]))
        : null;
}
