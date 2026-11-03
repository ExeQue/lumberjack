import { formatCompact, formatNumber, formatPercent } from '@/lib/format';
import type {
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

    return JSON.stringify([
        widget.type,
        widget.source,
        widget.rotate,
        widget.scope,
        options,
    ]);
}

/** The sources a tile shows in turn: the picked one first, then those it rotates through. */
export function sourcesOf(widget: Widget): string[] {
    if (widget.source === null) {
        return [];
    }

    return ROTATING_KINDS.includes(widget.type)
        ? [widget.source, ...widget.rotate.filter((id) => id !== widget.source)]
        : [widget.source];
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
 * Which source a rotating tile shows at this moment. Every tile and every kiosk counts
 * from the same clock, so tiles switch together; one that needs attention holds.
 */
export function rotationIndex(
    kind: WidgetKind,
    sources: string[],
    data: WidgetData | undefined,
    everySeconds: number,
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

    return Math.floor(now / 1000 / Math.max(1, everySeconds)) % sources.length;
}

/** A value in its unit: counts compact, shares as percent, durations in ms or s. */
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

/** Cells the widgets need, each counted at most the size of the grid. */
export function cellsUsed(
    widgets: Widget[],
    cols: number,
    rows: number,
): number {
    return widgets.reduce(
        (n, w) => n + Math.min(w.w, cols) * Math.min(w.h, rows),
        0,
    );
}

/**
 * Where each widget lands, the way the CSS grid places them (row by row, in order, no
 * gap filling behind the last one placed), and whether it lands inside the screen's
 * rows. A widget wider than the grid is clamped to it, as on the TV.
 */
export function placements(
    widgets: Widget[],
    cols: number,
    rows: number,
): { row: number; col: number; fits: boolean }[] {
    const taken = new Set<string>();
    let cursorRow = 0;
    let cursorCol = 0;

    return widgets.map((widget) => {
        const w = Math.min(widget.w, cols);
        const h = Math.min(widget.h, rows);
        const free = (row: number, col: number) => {
            for (let r = row; r < row + h; r++) {
                for (let c = col; c < col + w; c++) {
                    if (taken.has(`${r}:${c}`)) {
                        return false;
                    }
                }
            }

            return true;
        };
        let row = cursorRow;
        let col = cursorCol;

        while (col + w > cols || !free(row, col)) {
            col++;

            if (col + w > cols) {
                col = 0;
                row++;
            }
        }

        for (let r = row; r < row + h; r++) {
            for (let c = col; c < col + w; c++) {
                taken.add(`${r}:${c}`);
            }
        }

        cursorRow = row;
        cursorCol = col + w;

        return { row, col, fits: row + h <= rows };
    });
}
