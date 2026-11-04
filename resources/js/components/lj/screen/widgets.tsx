import { useEffect, useRef, useState } from 'react';
import type {
    ComponentProps,
    CSSProperties,
    ReactNode,
    RefObject,
} from 'react';
import { formatBytes, formatNumber, formatPercent } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    formatValue,
    incident as incidentPreview,
    option,
    keysOf,
    rotationAt,
    rotationOf,
    shownAs,
    statusOf,
} from './lib';
import type { Schema, Sources, Status, Widget, WidgetData } from './types';

type Payload = Record<string, unknown>;
type Translate = ReturnType<typeof useT>;

const SCROLL_PX_PER_SECOND = 18;
const SCROLL_PAUSE_MS = 4000;

/**
 * Content taller than its tile glides down, rests, glides back up and rests again, so a
 * wall shows every row without anyone touching it. Off with reduced motion, and while
 * the pointer is over it (someone is reading).
 */
function useAutoScroll<T extends HTMLElement>(enabled: boolean) {
    const ref = useRef<T>(null);

    useEffect(() => {
        const el = ref.current;

        if (
            !enabled ||
            !el ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        let frame = 0;
        let last = 0;
        let direction = 1;
        let restUntil = performance.now() + SCROLL_PAUSE_MS;
        let hovered = false;
        let position = el.scrollTop;
        const enter = () => (hovered = true);
        const leave = () => (hovered = false);
        el.addEventListener('pointerenter', enter);
        el.addEventListener('pointerleave', leave);

        const step = (now: number) => {
            const room = el.scrollHeight - el.clientHeight;
            const dt = last ? (now - last) / 1000 : 0;
            last = now;

            if (room <= 1) {
                position = 0;
                el.scrollTop = 0;
            } else if (!hovered && now >= restUntil) {
                position = Math.min(
                    room,
                    Math.max(
                        0,
                        position + direction * SCROLL_PX_PER_SECOND * dt,
                    ),
                );
                el.scrollTop = position;

                if (position <= 0 || position >= room) {
                    direction = -direction;
                    restUntil = now + SCROLL_PAUSE_MS;
                }
            }

            frame = requestAnimationFrame(step);
        };

        frame = requestAnimationFrame(step);

        return () => {
            cancelAnimationFrame(frame);
            el.removeEventListener('pointerenter', enter);
            el.removeEventListener('pointerleave', leave);
        };
    }, [enabled]);

    return ref;
}

/** A translation, or the fallback when the key has none. */
function orKey(t: Translate, key: string, fallback: string): string {
    const text = t(key);

    return text === key ? fallback : text;
}

/** Status words and colors per check state, worst first. */
/** Share of a database's connections in use that turns amber and red, as the gauge. */
const CONNECTIONS_AMBER = 0.8;
const CONNECTIONS_RED = 0.9;

function hasDatabaseFigures(
    metrics: Record<string, number> | undefined,
): boolean {
    return (
        metrics?.connections !== undefined || metrics?.size_bytes !== undefined
    );
}

/** A database check's latest connections and size: "4 of 100 conns · 13.8 MB". */
function DatabaseLine({ metrics }: { metrics: Record<string, number> }) {
    const t = useT();
    const { connections, max_connections: max, size_bytes: size } = metrics;

    if (connections === undefined && size === undefined) {
        return null;
    }

    const share = connections !== undefined && max ? connections / max : null;
    const color =
        share === null
            ? undefined
            : share >= CONNECTIONS_RED
              ? 'var(--s-red)'
              : share >= CONNECTIONS_AMBER
                ? 'var(--s-amber)'
                : undefined;

    return (
        <span className="lj-tile-small max-w-full shrink-0 truncate text-[0.68em] leading-[1.15]">
            {connections !== undefined && (
                <span style={{ color }}>
                    {max !== undefined
                        ? t('screens.health.connections_of', {
                              used: formatNumber(connections),
                              max: formatNumber(max),
                          })
                        : t('screens.health.connections', {
                              count: formatNumber(connections),
                          })}
                </span>
            )}
            {connections !== undefined && size !== undefined && ' · '}
            {size !== undefined && formatBytes(size)}
        </span>
    );
}

const STATE_COLOR: Record<string, string> = {
    down: 'var(--s-red)',
    degraded: 'var(--s-amber)',
    recovering: 'var(--s-blue)',
    stale: 'var(--s-ink-3)',
    pending: 'var(--s-ink-3)',
    ok: 'var(--s-green)',
};

const CARD_BG: Record<string, string> = {
    down: 'var(--s-red-card)',
    degraded: 'var(--s-amber-card)',
    recovering: 'var(--s-blue-card)',
};

const ROW_COLOR: Record<string, string> = {
    red: 'var(--s-red)',
    amber: 'var(--s-amber)',
    ok: 'var(--s-green)',
};

/** Big value size per text size, in units of the tile (fit to tile by default). */
const BIG_SIZE: Record<string, string> = {
    auto: 'min(40cqh, 17cqw)',
    s: 'min(22cqh, 10cqw)',
    m: 'min(30cqh, 13cqw)',
    l: 'min(42cqh, 18cqw)',
    xl: 'min(56cqh, 24cqw)',
};

/** Title size relative to the tile's text. */
const TITLE_SIZE: Record<string, string> = {
    s: '0.85em',
    m: '1em',
    l: '1.3em',
    xl: '1.7em',
};

const TEXT_SCALE: Record<string, number> = {
    auto: 1,
    s: 0.85,
    m: 1,
    l: 1.2,
    xl: 1.45,
};

/** A duration, short: "45 sec", "6 min", "2 hr", "3 days". */
function duration(seconds: number): string {
    const [value, unit] =
        seconds < 60
            ? [seconds, 'second']
            : seconds < 3600
              ? [seconds / 60, 'minute']
              : seconds < 86400
                ? [seconds / 3600, 'hour']
                : [seconds / 86400, 'day'];

    return new Intl.NumberFormat(document.documentElement.lang || 'en', {
        style: 'unit',
        unit,
        unitDisplay: 'short',
        maximumFractionDigits: 0,
    }).format(Math.floor(value));
}

/** How long since then, short. */
/** An age in one narrow unit, for a corner label: 42s, 6m, 1h, 4d. */
function shortAge(iso: string, now: number): string {
    const seconds = Math.max(0, (now - new Date(iso).getTime()) / 1000);
    const [value, unit] =
        seconds < 60
            ? [seconds, 'second']
            : seconds < 3600
              ? [seconds / 60, 'minute']
              : seconds < 86400
                ? [seconds / 3600, 'hour']
                : [seconds / 86400, 'day'];

    return new Intl.NumberFormat(document.documentElement.lang || 'en', {
        style: 'unit',
        unit,
        unitDisplay: 'narrow',
        maximumFractionDigits: 0,
    }).format(Math.floor(value));
}

function lasted(iso: string | null | undefined, now: number): string {
    return iso
        ? duration(Math.max(0, (now - new Date(iso).getTime()) / 1000))
        : '';
}

/** The next round number at or above n: 1, 2, 2.5 or 5 times a power of ten. */
function niceCeil(n: number): number {
    if (n <= 0) {
        return 1;
    }

    const power = 10 ** Math.floor(Math.log10(n));

    return (
        ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find(
            (step) => step * power >= n,
        ) ?? 10) * power
    );
}

function timeOf(iso: string, withDate: boolean): string {
    return new Intl.DateTimeFormat(document.documentElement.lang || 'en', {
        ...(withDate ? { day: 'numeric', month: 'short' } : {}),
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

/** The size of an element, kept current. */
function useSize<T extends HTMLElement>(): [
    RefObject<T | null>,
    { w: number; h: number; em: number },
] {
    const ref = useRef<T | null>(null);
    const [size, setSize] = useState({ w: 0, h: 0, em: 14 });

    useEffect(() => {
        const el = ref.current;

        if (!el) {
            return;
        }

        const observer = new ResizeObserver(() =>
            setSize({
                w: el.clientWidth,
                h: el.clientHeight,
                em: parseFloat(getComputedStyle(el).fontSize) || 14,
            }),
        );
        observer.observe(el);

        return () => observer.disconnect();
    }, []);

    return [ref, size];
}

/** How far the corner label and the gear sit from the tile's top and right edges. */
const CORNER_INSET = '0.8em';

export type TileProps = {
    widget: Widget;
    schema: Schema;
    sources: Sources;
    data: WidgetData | undefined;
    now: number;
    /** The screen's time zone, for a clock without its own; null: the browser's. */
    timezone?: string | null;
    /** The designer holds the selected tile on the value its inspector has open. */
    pinned?: number;
    incident?: boolean;
    designer?: boolean;
    flash?: boolean;
    /** Extra content above the tile's own: the designer's badge and resize handle. */
    decoration?: ReactNode;
    /** In the tile's top right corner, the period label moving aside: the designer's gear. */
    corner?: ReactNode;
    /** Told the tile's status whenever it changes, for the kiosk's flash and count. */
    onStatus?: (id: string, status: Status) => void;
} & Omit<ComponentProps<'div'>, 'children'>;

/**
 * One tile of a screen, the same in the designer and on the TV. Its type draws the data
 * of the source it shows now; its state colors it.
 */
export function WidgetTile({
    widget: stored,
    schema,
    sources,
    data,
    now,
    timezone = null,
    pinned,
    incident = false,
    designer = false,
    flash = false,
    decoration,
    corner,
    onStatus,
    className,
    style,
    ...props
}: TileProps) {
    const t = useT();
    const items = rotationOf(stored);
    const shown = items.map((item) => item.source);
    // Where each value's data is: a health widget shows its one source several times.
    const keys = keysOf(items);
    const every = Number(option<string>(schema, stored, 'rotate_every') ?? 10);
    const preview = (key: string) =>
        incident ? incidentPreview(stored.type, data?.[key]) : data?.[key];
    const previewData: WidgetData | undefined = data
        ? Object.fromEntries(keys.map((key) => [key, preview(key) ?? null]))
        : undefined;
    const slot = rotationAt(
        stored.type,
        keys,
        previewData,
        items.map((item) => item.seconds ?? every),
        option<boolean>(schema, stored, 'hold_problem') !== false,
        now,
    );
    // The designer holds the tile on the open tab, with no progress to show.
    const target =
        pinned !== undefined
            ? Math.min(pinned, Math.max(0, items.length - 1))
            : slot.index;
    const transition = option<string>(schema, stored, 'transition') ?? 'fade';
    // The value on screen: the old one stays while it leaves, then the new one comes in.
    const [drawn, leaving] = useOutIn(
        target,
        shown.length > 1 && transition !== 'none',
    );
    // A value taken out of the rotation while it showed: the last one left.
    const index = Math.min(drawn, Math.max(0, shown.length - 1));
    // Title, corner label and value move together, out and then in.
    const motion =
        shown.length < 2 || transition === 'none'
            ? undefined
            : leaving
              ? cn(
                    'animate-out fill-mode-forwards fade-out motion-reduce:animate-none',
                    transition === 'slide' && 'slide-out-to-left-8',
                )
              : cn(
                    'animate-in fade-in motion-reduce:animate-none',
                    transition === 'slide' && 'slide-in-from-right-8',
                );
    const motionStyle = motion
        ? { animationDuration: `${SWITCH_MS}ms` }
        : undefined;
    // The value shown now draws with its own settings over the widget's.
    const widget = shownAs(stored, index > 0 ? items[index] : undefined);
    const opt = <T,>(key: string) => option<T>(schema, widget, key);
    const sourceId = shown[index] ?? null;
    const payload = sourceId ? previewData?.[keys[index]] : undefined;
    const source = sources[widget.type]?.find((s) => s.id === sourceId);
    const colored = opt<boolean>('status_colors') !== false;
    const status: Status = colored ? statusOf(widget.type, payload) : null;
    // One picked health check names the tile after itself unless it has a title.
    const single = (payload?.check as { name?: string } | null | undefined)
        ?.name;
    const title =
        shown.length > 1
            ? (items[index]?.title ?? single ?? source?.label ?? '')
            : (widget.title ??
              single ??
              source?.label ??
              t(`screens.kinds.${widget.type}`));
    const showTitle = opt<boolean>('show_title') !== false;

    useEffect(() => {
        onStatus?.(widget.id, status);
    }, [onStatus, widget.id, status]);
    const textSize = opt<string>('text_size') ?? 'auto';
    const align = opt<string>('align') === 'center' ? 'center' : 'left';
    const middle = opt<string>('valign') === 'middle';
    const hideWhen = opt<string>('hide_when') ?? 'never';
    const empty =
        payload !== undefined &&
        (payload === null ||
            (widget.type === 'list' &&
                ((payload.rows as unknown[]) ?? []).length === 0) ||
            (widget.type === 'health' &&
                ((payload.checks as unknown[]) ?? []).length === 0));
    const hidden =
        (hideWhen === 'ok' &&
            payload &&
            status !== 'down' &&
            status !== 'degraded') ||
        (hideWhen === 'empty' && empty);
    // The top right label: a list's order, then the period or how old the report is.
    const cornerText = [
        widget.type === 'list' && sourceId
            ? orKey(
                  t,
                  `screens.list.sort_hint.${sourceId}.${opt<string>('sort') ?? 'default'}`,
                  t(`screens.list.sort_hint.${sourceId}.default`),
              )
            : '',
        // What time it covers, so a 1 h tile next to a 24 h one is not misread.
        opt<boolean>('show_period') !== false &&
        typeof payload?.period === 'string'
            ? // The latest report says how old it is; a period says how long.
              typeof payload.as_of === 'string'
                ? shortAge(payload.as_of, now)
                : t(`screens.period_short.${payload.period}`)
            : '',
    ]
        .filter(Boolean)
        .join(' · ');
    const cornerClass = cn(
        'lj-tile-small whitespace-nowrap',
        // Room for the designer's gear, whatever the label says.
        corner && 'min-w-[1.5em] text-right',
    );
    const cornerStyle = { fontSize: 'var(--lj-cell-font)' };
    // How long the value has left: frozen (none) while it holds the tile or a tab pins it.
    const progress =
        pinned === undefined &&
        slot.elapsed !== null &&
        opt<boolean>('progress') !== false;

    let body: ReactNode = null;

    if (widget.type === 'empty') {
        body = designer ? (
            <span className="m-auto text-[0.8em] text-(--s-ink-4)">
                {t('screens.kinds.empty')}
            </span>
        ) : null;
    } else if (widget.type === 'clock') {
        body = (
            <Clock
                widget={widget}
                schema={schema}
                now={now}
                screenZone={timezone}
            />
        );
    } else if (payload === undefined) {
        body = (
            <span className="lj-tile-small animate-pulse">
                {t('screens.widget.loading')}
            </span>
        );
    } else if (payload === null) {
        body = (
            <span className="lj-tile-small">{t('screens.widget.failed')}</span>
        );
    } else {
        const bodyProps = { widget, schema, data: payload, now, t, textSize };

        body =
            widget.type === 'stat' ? (
                <Stat {...bodyProps} colored={colored} />
            ) : widget.type === 'gauge' ? (
                <Gauge {...bodyProps} colored={colored} />
            ) : widget.type === 'chart' ? (
                <Chart {...bodyProps} />
            ) : widget.type === 'list' ? (
                <List {...bodyProps} />
            ) : (
                <Health {...bodyProps} />
            );
    }

    return (
        <div
            {...props}
            className={cn('lj-tile', className)}
            data-kind={widget.type}
            data-status={
                // A calm tile can drop its green border; problems always show.
                status === 'ok' && opt<boolean>('ok_border') === false
                    ? undefined
                    : (status ?? undefined)
            }
            data-flash={flash || undefined}
            style={{
                gridColumn: `${widget.x + 1} / span ${widget.w}`,
                gridRow: `${widget.y + 1} / span ${widget.h}`,
                textAlign: align,
                ['--lj-text-scale' as string]: TEXT_SCALE[textSize] ?? 1,
                visibility: hidden && !designer ? 'hidden' : undefined,
                opacity: hidden && designer ? 0.45 : undefined,
                ...style,
            }}
        >
            {decoration}
            {(corner ||
                (showTitle &&
                    widget.type !== 'empty' &&
                    title &&
                    cornerText)) && (
                // The corner label, as far from the top as from the right edge, with the
                // designer's gear taking its place while it shows.
                <span
                    className="pointer-events-none absolute z-20 flex"
                    style={{ top: CORNER_INSET, right: CORNER_INSET }}
                >
                    {showTitle && widget.type !== 'empty' && title && (
                        <span
                            key={`${keys[index] ?? ''}:corner`}
                            className={cn(
                                // Up by the space above a digit, so the text itself sits
                                // at the inset (IBM Plex Sans: 0.2em at line-height 1).
                                'lj-tile-small -mt-[0.2em] leading-none whitespace-nowrap',
                                corner && 'lj-corner-label',
                                motion,
                            )}
                            // The same size on every tile, whatever its text or title size.
                            style={{ ...cornerStyle, ...motionStyle }}
                        >
                            {cornerText}
                        </span>
                    )}
                    {corner && (
                        <span
                            className="pointer-events-auto absolute top-0 right-0 flex"
                            style={cornerStyle}
                        >
                            {corner}
                        </span>
                    )}
                </span>
            )}
            {showTitle && widget.type !== 'empty' && title && (
                // Invisible twins of the label keep room for it on the right, and on the
                // left too for a centered title, so that stays centered on the tile.
                <div
                    // Its own key: the value below already uses the plain one.
                    key={`${keys[index] ?? ''}:title`}
                    className={cn(
                        'grid items-baseline gap-[0.6em]',
                        align === 'center'
                            ? 'grid-cols-[auto_minmax(0,1fr)_auto]'
                            : 'grid-cols-[minmax(0,1fr)_auto]',
                        motion,
                    )}
                    style={motionStyle}
                >
                    {align === 'center' && (
                        <span
                            aria-hidden="true"
                            className={cn(cornerClass, 'invisible')}
                            style={cornerStyle}
                        >
                            {cornerText}
                        </span>
                    )}
                    <span
                        className="lj-tile-title min-w-0 truncate"
                        style={{
                            fontSize:
                                TITLE_SIZE[opt<string>('title_size') ?? 'm'],
                        }}
                    >
                        {title}
                    </span>
                    <span
                        aria-hidden="true"
                        className={cn(cornerClass, 'invisible')}
                        style={cornerStyle}
                    >
                        {cornerText}
                    </span>
                </div>
            )}
            <div
                key={keys[index] ?? 'tile'}
                className={cn(
                    'flex min-h-0 flex-1 flex-col gap-[0.3em]',
                    motion,
                )}
                style={{
                    alignItems: align === 'center' ? 'center' : undefined,
                    justifyContent: middle ? 'center' : undefined,
                    ...motionStyle,
                }}
            >
                {body}
            </div>
            {progress && (
                <RotationProgress
                    // A new bar for every value shown, so it starts over on the switch.
                    key={`${keys[target]}@${Math.floor(now / 1000) - (slot.elapsed ?? 0)}`}
                    elapsed={slot.elapsed ?? 0}
                    seconds={slot.seconds}
                    status={status}
                />
            )}
        </div>
    );
}

/**
 * A thin bar along the bottom of a rotating tile, growing from the center to full width
 * when the next value takes over. It runs as a CSS animation from where the value was
 * when it mounted, so it stays smooth between the clock's ticks; reduced motion hides it.
 */
function RotationProgress({
    elapsed,
    seconds,
    status,
}: {
    elapsed: number;
    seconds: number;
    status: Status;
}) {
    // Where it was when it mounted; later renders must not move the animation.
    const [start] = useState(elapsed);

    return (
        <span
            aria-hidden="true"
            className="lj-progress pointer-events-none absolute inset-x-0 bottom-0 h-[3px] origin-center"
            style={{
                background:
                    status === 'down'
                        ? 'var(--s-red)'
                        : status === 'degraded'
                          ? 'var(--s-amber)'
                          : 'var(--s-ink-4)',
                animationDuration: `${seconds}s`,
                animationDelay: `-${start}s`,
            }}
        />
    );
}

type BodyProps = {
    widget: Widget;
    schema: Schema;
    data: Payload;
    now: number;
    t: Translate;
    textSize: string;
};

/**
 * A trend is good or bad only for a value with limits: up is bad unless low values are bad.
 * Without limits (requests, users) it stays neutral. A change under 2% is noise.
 */
function trendColor(
    data: Payload,
    change: number,
    colored: boolean,
): string | undefined {
    const limits = data.limits as
        | { amber?: unknown; red?: unknown; low_is_bad?: boolean }
        | undefined;

    if (
        !colored ||
        !limits ||
        (limits.amber == null && limits.red == null) ||
        Math.abs(change) < 0.02
    ) {
        return undefined;
    }

    const worse = limits.low_is_bad ? change < 0 : change > 0;

    return worse ? 'var(--s-amber)' : 'var(--s-green)';
}

const valueColor = (state: unknown, colored: boolean): string =>
    !colored
        ? 'var(--s-ink)'
        : state === 'red'
          ? 'var(--s-red)'
          : state === 'amber'
            ? 'var(--s-amber)'
            : 'var(--s-ink)';

function Stat({
    widget,
    schema,
    data,
    now,
    t,
    textSize,
    colored,
}: BodyProps & { colored: boolean }) {
    const release = data.release as
        | {
              since: string;
              previous: string | null;
              error_rate: number | null;
              error_rate_change: number | null;
          }
        | undefined;
    const opt = <T,>(key: string) => option<T>(schema, widget, key);
    const compare = opt<string>('compare') ?? 'previous';
    const change = data.change as number | null;
    const spark = (data.sparkline as { v: number | null }[] | null) ?? null;
    const extra =
        (compare !== 'none' && change !== null) || !!spark || !!release;
    const value = formatValue(
        data.value,
        String(data.unit),
        opt<string>('decimals') ?? 'auto',
    );

    return (
        <>
            <span
                className="lj-tile-big"
                style={{
                    fontSize:
                        textSize === 'auto' && extra
                            ? 'min(36cqh, 16cqw)'
                            : BIG_SIZE[textSize],
                    color: valueColor(data.state, colored),
                }}
            >
                {(opt<string>('prefix') ?? '') +
                    value +
                    (opt<string>('suffix') ?? '')}
            </span>
            {compare !== 'none' && change !== null && (
                <span
                    className="lj-tile-small"
                    style={{ color: trendColor(data, change, colored) }}
                >
                    {(change >= 0 ? '▲ ' : '▼ ') +
                        formatPercent(Math.abs(change), 0) +
                        ' ' +
                        t(`screens.trend.${compare}`)}
                </span>
            )}
            {release && (
                <span className="lj-tile-small flex flex-wrap gap-x-[0.6em]">
                    <span>
                        {t('screens.release.since', {
                            age: lasted(release.since, now),
                        })}
                    </span>
                    {release.previous && (
                        <span>
                            {t('screens.release.previous', {
                                release: release.previous,
                            })}
                        </span>
                    )}
                    {release.error_rate !== null && (
                        <span
                            style={{
                                color:
                                    colored &&
                                    (release.error_rate_change ?? 0) > 0.1
                                        ? 'var(--s-red)'
                                        : undefined,
                            }}
                        >
                            {t('screens.release.errors', {
                                rate: formatPercent(release.error_rate, 1),
                            }) +
                                (release.error_rate_change !== null &&
                                Math.abs(release.error_rate_change) >= 0.02
                                    ? release.error_rate_change > 0
                                        ? ' ▲'
                                        : ' ▼'
                                    : '')}
                        </span>
                    )}
                </span>
            )}
            {/* Too few points to draw yet: keep the line's room, so the value sits
                where it does on every stat. */}
            {spark && spark.length <= 1 && (
                <span aria-hidden="true" className="min-h-[10px] flex-1" />
            )}
            {spark && spark.length > 1 && (
                <Spark
                    points={spark}
                    color={
                        colored &&
                        (data.state === 'red' || data.state === 'amber')
                            ? valueColor(data.state, colored)
                            : 'var(--s-blue)'
                    }
                />
            )}
        </>
    );
}

/**
 * An SVG path through the points: straight segments, or a monotone cubic curve that
 * never overshoots the data (so a smooth line stays above zero and below the peak).
 */
function curve(pts: [number, number][], smooth: boolean): string {
    if (!smooth || pts.length < 3) {
        return 'M' + pts.map((p) => p.join(',')).join(' L');
    }

    const n = pts.length;
    const d = pts
        .slice(1)
        .map((p, i) => (p[1] - pts[i][1]) / (p[0] - pts[i][0] || 1));
    const m = pts.map((_, i) =>
        i === 0
            ? d[0]
            : i === n - 1
              ? d[n - 2]
              : d[i - 1] * d[i] <= 0
                ? 0
                : (d[i - 1] + d[i]) / 2,
    );

    // Fritsch-Carlson: limit the tangents so each segment stays monotone.
    for (let i = 0; i < n - 1; i++) {
        if (d[i] === 0) {
            m[i] = m[i + 1] = 0;
            continue;
        }

        const a = m[i] / d[i];
        const b = m[i + 1] / d[i];
        const len = a * a + b * b;

        if (len > 9) {
            const t = 3 / Math.sqrt(len);
            m[i] = t * a * d[i];
            m[i + 1] = t * b * d[i];
        }
    }

    let path = `M${pts[0][0]},${pts[0][1]}`;

    for (let i = 0; i < n - 1; i++) {
        const [x0, y0] = pts[i];
        const [x1, y1] = pts[i + 1];
        const h = (x1 - x0) / 3;
        path += ` C${x0 + h},${y0 + m[i] * h} ${x1 - h},${y1 - m[i + 1] * h} ${x1},${y1}`;
    }

    return path;
}

/** How long a rotating tile's value takes to leave, and then to come in. */
const SWITCH_MS = 300;

/**
 * The index to draw while a rotating tile switches: the old one for SWITCH_MS while it
 * leaves (leaving true), then the new one. Without animation, the new one at once.
 */
function useOutIn(target: number, animate: boolean): [number, boolean] {
    const [shownIndex, setShownIndex] = useState(target);

    useEffect(() => {
        if (target === shownIndex) {
            return;
        }

        if (!animate) {
            setShownIndex(target);

            return;
        }

        const timer = window.setTimeout(() => setShownIndex(target), SWITCH_MS);

        return () => window.clearTimeout(timer);
    }, [target, shownIndex, animate]);

    return [shownIndex, shownIndex !== target];
}

/** A soft area line of the recent values, in the stat's own color. */
function Spark({
    points,
    color,
}: {
    points: { v: number | null }[];
    color: string;
}) {
    const values = points.map((p) => p.v ?? 0);
    const max = Math.max(...values);
    const min = Math.min(...values);
    const span = max - min || 1;
    const step = 100 / Math.max(1, values.length - 1);
    // Keep a little room top and bottom so the line never touches the edges.
    const y = (v: number) => 27 - ((v - min) / span) * 24;
    const line = values
        .map(
            (v, i) =>
                `${i === 0 ? 'M' : 'L'}${(i * step).toFixed(2)} ${y(v).toFixed(2)}`,
        )
        .join(' ');

    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 100 30"
            preserveAspectRatio="none"
            className="min-h-[10px] w-full flex-1"
        >
            <path d={`${line} L100 30 L0 30 Z`} fill={color} opacity={0.14} />
            <path
                d={line}
                fill="none"
                stroke={color}
                strokeWidth={1.6}
                strokeLinejoin="round"
                strokeLinecap="round"
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}

function Gauge({
    widget,
    schema,
    data,
    t,
    colored,
}: BodyProps & { colored: boolean }) {
    const unit = String(data.unit);
    const value = data.value as number | null;
    const min = Number(data.min ?? 0);
    const max = Number(data.max ?? 1);
    const limits = data.limits as {
        amber: number | null;
        red: number | null;
        low_is_bad: boolean;
    };
    const len = 157.08;
    const zl = 122.52;
    const fraction = (v: number) =>
        Math.max(0, Math.min(1, (v - min) / (max - min || 1)));
    const f = value === null ? 0 : fraction(value);
    const za = limits?.amber != null ? fraction(limits.amber) : null;
    const zr = limits?.red != null ? fraction(limits.red) : null;
    // A stretch of the inner arc, from one fraction of the scale to another.
    const stretch = (from: number, to: number) =>
        to > from ? `0 ${zl * from} ${zl * (to - from)} ${zl}` : `0 ${zl}`;
    // Amber runs up to red (or the end); red runs on to the end. Low-is-bad mirrors it.
    // With the red mark hidden, amber fills the whole stretch past its limit.
    const showRed = option<boolean>(schema, widget, 'show_red_limit') !== false;
    const amberUntil = showRed ? zr : null;
    const amberZone =
        za === null
            ? `0 ${zl}`
            : limits.low_is_bad
              ? stretch(amberUntil ?? 0, za)
              : stretch(za, amberUntil ?? 1);
    const redZone =
        zr === null
            ? `0 ${zl}`
            : limits.low_is_bad
              ? stretch(0, zr)
              : stretch(zr, 1);
    const color =
        !colored || !data.state
            ? 'var(--s-ink)'
            : data.state === 'red'
              ? 'var(--s-red)'
              : data.state === 'amber'
                ? 'var(--s-amber)'
                : 'var(--s-green)';
    const text = formatValue(value, unit);

    return (
        <div className="relative min-h-0 w-full flex-1">
            <svg
                viewBox="0 0 120 80"
                className="absolute inset-0 h-full w-full"
                role="img"
                aria-label={t('screens.gauge.label', {
                    value: text,
                    max: formatValue(max, unit),
                })}
            >
                <path
                    d="M10 62 A50 50 0 0 1 110 62"
                    fill="none"
                    stroke="var(--s-line)"
                    strokeWidth="10"
                    strokeLinecap="round"
                />
                {option<boolean>(schema, widget, 'show_limits') !== false && (
                    <path
                        d="M21 62 A39 39 0 0 1 99 62"
                        fill="none"
                        stroke="var(--s-amber)"
                        strokeWidth="2"
                        strokeDasharray={amberZone}
                        opacity="0.8"
                    />
                )}
                {option<boolean>(schema, widget, 'show_red_limit') !==
                    false && (
                    <path
                        d="M21 62 A39 39 0 0 1 99 62"
                        fill="none"
                        stroke="var(--s-red)"
                        strokeWidth="2"
                        strokeDasharray={redZone}
                        opacity="0.8"
                    />
                )}
                <path
                    d="M10 62 A50 50 0 0 1 110 62"
                    fill="none"
                    stroke={color}
                    strokeWidth="10"
                    strokeLinecap="round"
                    strokeDasharray={`${len * f} ${len}`}
                />
                <text
                    x="60"
                    y="56"
                    textAnchor="middle"
                    fill={color}
                    style={{
                        fontFamily: 'var(--font-mono)',
                        fontSize: 17,
                        fontWeight: 700,
                    }}
                >
                    {text}
                </text>
                <text
                    x="10"
                    y="78"
                    textAnchor="middle"
                    fill="var(--s-ink-3)"
                    style={{ fontSize: 7 }}
                >
                    {formatValue(min, unit)}
                </text>
                <text
                    x="110"
                    y="78"
                    textAnchor="middle"
                    fill="var(--s-ink-3)"
                    style={{ fontSize: 7 }}
                >
                    {formatValue(max, unit)}
                </text>
            </svg>
        </div>
    );
}

/** A series color from the token map, by what the source measures. */
function seriesColor(source: string, i: number, split: boolean): string {
    if (split) {
        return [
            'var(--s-blue)',
            'var(--s-amber)',
            'var(--s-green)',
            'var(--s-red)',
            'var(--s-ink-3)',
        ][i % 5];
    }

    // Requests and errors draws its requests in the bar color; only the errors are red.
    if (source === 'requests_errors') {
        return 'var(--s-bar)';
    }

    if (/error|failed/.test(source)) {
        return 'var(--s-red)';
    }

    if (/p95|wait/.test(source)) {
        return 'var(--s-amber)';
    }

    if (/apdex|lcp/.test(source)) {
        return 'var(--s-green)';
    }

    return /requests/.test(source) ? 'var(--s-bar)' : 'var(--s-blue)';
}

type Series = { name: string; points: { t: string; v: number | null }[] };

function Chart({ widget, schema, data, t }: BodyProps) {
    const opt = <T,>(key: string) => option<T>(schema, widget, key);
    const all = (data.series as Series[]) ?? [];
    const previous = all.find((s) => s.name === 'previous');
    const stacked = data.source === 'requests_errors';
    const series = all.filter((s) => s !== previous);
    const split = !stacked && series.length > 1;
    // ponytail: bars and areas draw one series; a split always draws lines.
    const style = split ? 'line' : (opt<string>('style') ?? 'bars');
    const red =
        opt<boolean>('limit_line') !== false
            ? (data.red as number | null)
            : null;
    const values = [...series, ...(previous ? [previous] : [])].flatMap((s) =>
        s.points.map((p) => p.v ?? 0),
    );
    const showY = opt<boolean>('y_axis') !== false;
    const showGrid = opt<boolean>('grid') !== false;
    // A round top for the value axis, so its labels read 0, 1.5k, 3k rather than 0, 1472, 2944.
    const top = niceCeil(Math.max(1e-9, ...values, red ?? 0) * 1.05);
    const bottom =
        opt<boolean>('y_zero') !== false ? 0 : Math.min(...values, top) * 0.95;
    const points = series[0]?.points ?? [];
    const n = Math.max(1, points.length);
    const y = (v: number | null) =>
        40 - ((Math.max(bottom, v ?? 0) - bottom) / (top - bottom || 1)) * 40;
    const x = (i: number) => ((i + 0.5) / n) * 100;
    const smooth = opt<boolean>('smooth') === true;
    const line = (s: Series) =>
        curve(
            s.points.map((p, i): [number, number] => [x(i), y(p.v)]),
            smooth,
        );
    const withDate = !String(data.interval).match(/[ms]$/);
    const spansDays =
        points.length > 1 &&
        new Date(points[points.length - 1].t).getDate() !==
            new Date(points[0].t).getDate();
    // The date only where it changes along the axis; the time on every tick.
    const day = (iso: string | undefined) =>
        iso ? new Date(iso).toDateString() : '';
    const label = (iso: string | undefined, previous?: string) =>
        iso
            ? timeOf(
                  iso,
                  withDate &&
                      spansDays &&
                      (previous === undefined || day(previous) !== day(iso)),
              )
            : '';
    // Four bands on a tile two rows or taller, two on a one row tile.
    const bands = widget.h >= 2 ? 4 : 2;
    const yTicks = Array.from(
        { length: bands + 1 },
        (_, k) => top - ((top - bottom) * k) / bands,
    );
    // Room for a label every column or so: three ticks on a narrow chart, five on a wide one.
    const xTicks = Math.min(widget.w >= 3 ? 5 : 3, points.length);
    const xAt = Array.from({ length: xTicks }, (_, k) =>
        Math.round((k * (points.length - 1)) / Math.max(1, xTicks - 1)),
    );
    const total = series.reduce(
        (sum, s) => sum + s.points.reduce((a, p) => a + (p.v ?? 0), 0),
        0,
    );

    return (
        <>
            <div className="flex min-h-0 w-full flex-1 flex-col gap-[0.25em]">
                <div className="relative min-h-0 flex-1">
                    {showY &&
                        yTicks.slice(0, -1).map((v, i) => (
                            // Each label sits just under its grid line, out of the bars' way.
                            <span
                                key={i}
                                aria-hidden="true"
                                className="lj-tile-small pointer-events-none absolute left-0 z-10 rounded-[3px] bg-(--s-panel)/80 px-[0.2em] font-mono leading-none"
                                style={{
                                    top: `calc(${(i * 100) / bands}% + 0.2em)`,
                                }}
                            >
                                {formatValue(v, String(data.unit))}
                            </span>
                        ))}
                    <svg
                        viewBox="0 0 100 40"
                        preserveAspectRatio="none"
                        className="absolute inset-0 h-full w-full"
                        role="img"
                        aria-label={t('screens.chart.label', {
                            points: points.length,
                            total: formatValue(total, String(data.unit)),
                        })}
                    >
                        {showGrid &&
                            yTicks
                                .map((_, k) => (40 * k) / bands)
                                .map((gy) => (
                                    <line
                                        key={gy}
                                        x1="0"
                                        x2="100"
                                        y1={gy}
                                        y2={gy}
                                        stroke="var(--s-line)"
                                        strokeWidth="1"
                                        vectorEffect="non-scaling-stroke"
                                    />
                                ))}
                        {style === 'bars' &&
                            points.map((p, i) => {
                                const err = stacked
                                    ? (series[1]?.points[i]?.v ?? 0)
                                    : 0;

                                return (
                                    <g key={i}>
                                        <rect
                                            x={x(i) - 40 / n}
                                            width={80 / n}
                                            y={y(p.v)}
                                            height={40 - y(p.v)}
                                            rx="0.4"
                                            fill={seriesColor(
                                                String(data.source),
                                                0,
                                                false,
                                            )}
                                        />
                                        {err > 0 && (
                                            <rect
                                                x={x(i) - 40 / n}
                                                width={80 / n}
                                                // A few errors under a lot of traffic stay visible.
                                                y={Math.min(y(err), 38.8)}
                                                height={Math.max(
                                                    40 - y(err),
                                                    1.2,
                                                )}
                                                rx="0.4"
                                                fill="var(--s-red)"
                                            />
                                        )}
                                    </g>
                                );
                            })}
                        {style === 'area' && series[0] && (
                            <path
                                d={`${line(series[0])} L${x(n - 1)},40 L${x(0)},40 Z`}
                                fill={seriesColor(
                                    String(data.source),
                                    0,
                                    false,
                                )}
                                opacity="0.6"
                            />
                        )}
                        {(style === 'line' || style === 'area') &&
                            series
                                .slice(
                                    0,
                                    stacked && style === 'area' ? 1 : undefined,
                                )
                                .map((s, i) => (
                                    <path
                                        key={s.name}
                                        d={line(s)}
                                        fill="none"
                                        stroke={
                                            stacked && i === 1
                                                ? 'var(--s-red)'
                                                : seriesColor(
                                                      String(data.source),
                                                      i,
                                                      split,
                                                  )
                                        }
                                        strokeWidth="1.6"
                                        vectorEffect="non-scaling-stroke"
                                    />
                                ))}
                        {previous && (
                            <path
                                d={line(previous)}
                                fill="none"
                                stroke="var(--s-ink-4)"
                                strokeWidth="1.2"
                                strokeDasharray="3 3"
                                vectorEffect="non-scaling-stroke"
                            />
                        )}
                        {red !== null && red <= top && (
                            <line
                                x1="0"
                                x2="100"
                                y1={y(red)}
                                y2={y(red)}
                                stroke="var(--s-red)"
                                strokeWidth="1"
                                strokeDasharray="4 3"
                                vectorEffect="non-scaling-stroke"
                            />
                        )}
                    </svg>
                </div>
                {opt<boolean>('axis') !== false && points.length > 1 && (
                    <div
                        aria-hidden="true"
                        className="lj-tile-small relative h-[1.2em] font-mono"
                    >
                        {xAt.map((i, k) => (
                            <span
                                key={i}
                                className="absolute whitespace-nowrap"
                                style={{
                                    left: `${x(i)}%`,
                                    transform:
                                        k === 0
                                            ? 'translateX(-10%)'
                                            : k === xAt.length - 1
                                              ? 'translateX(-90%)'
                                              : 'translateX(-50%)',
                                }}
                            >
                                {label(
                                    points[i]?.t,
                                    k > 0 ? points[xAt[k - 1]]?.t : undefined,
                                )}
                            </span>
                        ))}
                    </div>
                )}
            </div>
            {opt<boolean>('legend') && series.length > 0 && (
                <div className="lj-tile-small flex flex-wrap gap-x-3">
                    {series.map((s, i) => (
                        <span key={s.name} className="flex items-center gap-1">
                            <span
                                aria-hidden="true"
                                className="size-2 rounded-full"
                                style={{
                                    background:
                                        stacked && i === 1
                                            ? 'var(--s-red)'
                                            : seriesColor(
                                                  String(data.source),
                                                  i,
                                                  split,
                                              ),
                                }}
                            />
                            {t(`screens.chart.series.${s.name}`) ===
                            `screens.chart.series.${s.name}`
                                ? s.name
                                : t(`screens.chart.series.${s.name}`)}
                        </span>
                    ))}
                </div>
            )}
        </>
    );
}

type Row = Record<string, string | number | boolean | null | undefined> & {
    state?: string | null;
    project?: string;
};

/** A list row as columns: a short key, the text, a colored tag, a count and its age. */
type RowText = {
    key?: string;
    main: string;
    tag?: { text: string; tone: string | null };
    count?: string;
    at?: string;
};

function rowText(
    source: string,
    row: Row,
    t: Translate,
    narrow = false,
): RowText {
    const percent = (v: unknown) =>
        v === null || v === undefined ? '-' : formatPercent(Number(v), 0);
    const tone = row.state ?? null;

    switch (source) {
        case 'latest_issues':
            return {
                key: (row.reference as string) ?? undefined,
                main: String(
                    row.title ?? t(`screens.list.issue_status.${row.status}`),
                ),
                tag: {
                    text:
                        tone === 'red'
                            ? t('screens.list.escalating')
                            : tone === 'amber'
                              ? t('screens.list.regressed')
                              : t(`screens.list.issue_status.${row.status}`),
                    tone,
                },
                count: `× ${row.occurrences}`,
                at: row.last_seen_at as string,
            };
        case 'alert_feed':
            return {
                main: String(row.issue ?? ''),
                key: (row.channel as string) ?? undefined,
                tag:
                    tone === 'red'
                        ? { text: String(row.status), tone }
                        : undefined,
                at: row.sent_at as string,
            };
        case 'failed_jobs':
            return {
                main: String(row.name),
                count: `× ${row.failures}`,
                at: row.last_failed_at as string,
            };
        case 'scheduled_tasks':
            return {
                key: tone === 'red' ? '✕' : tone === 'amber' ? '!' : '✓',
                main: String(row.name),
                tag: row.status
                    ? {
                          text: orKey(
                              t,
                              `screens.list.task_status.${row.status}`,
                              String(row.status),
                          ),
                          tone: tone ?? 'ok',
                      }
                    : undefined,
                at: (row.last_run_at as string) ?? undefined,
            };
        case 'slowest_routes':
            return {
                key: formatValue(row.p95, 'ms'),
                main: `${row.method} ${row.route}`,
                count: `× ${row.requests}`,
            };
        case 'queues':
            return {
                key: String(row.queue),
                main: String(row.size ?? '-'),
                // Why it needs attention: too many waiting, or waiting too long.
                tag:
                    tone === 'red' || tone === 'amber'
                        ? {
                              text:
                                  row.reason === 'age'
                                      ? t('screens.list.queue.waiting', {
                                            age: duration(
                                                Number(row.oldest_age_s ?? 0),
                                            ),
                                        })
                                      : t('screens.list.queue.backlog'),
                              tone,
                          }
                        : undefined,
                count:
                    [
                        typeof row.workers === 'number'
                            ? t('screens.list.workers', {}, row.workers)
                            : null,
                        row.oldest_age_s !== null &&
                        row.oldest_age_s !== undefined
                            ? t('screens.list.oldest', {
                                  age: duration(Number(row.oldest_age_s)),
                              })
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ') || undefined,
            };
        case 'hosts': {
            // The host name gets the room; what it uses (or that it is offline) sits right.
            if (row.online === false) {
                return {
                    main: String(row.host),
                    tag: { text: t('screens.list.offline'), tone: 'red' },
                };
            }

            // Narrow: only the fullest of CPU, memory and disk, named.
            const usage = (
                [
                    ['cpu', row.cpu_load],
                    ['memory', row.memory_used],
                    ['disk', row.disk_used],
                ] as const
            ).filter(([, v]) => v !== null && v !== undefined);
            const [name, top] = usage.reduce(
                (a, b) => (Number(b[1]) > Number(a[1]) ? b : a),
                usage[0] ?? ['cpu', null],
            );

            return {
                main: String(row.host),
                tag: {
                    text: narrow
                        ? t(`screens.list.usage.${name}`, {
                              value: percent(top),
                          })
                        : t('screens.list.host_usage', {
                              cpu: percent(row.cpu_load),
                              memory: percent(row.memory_used),
                              disk: percent(row.disk_used),
                          }),
                    tone:
                        row.state === 'red' || row.state === 'amber'
                            ? String(row.state)
                            : null,
                },
            };
        }
        case 'agents':
            return {
                key: '●',
                main: [row.host, row.name].filter(Boolean).join(' · '),
                tag:
                    row.online === false
                        ? { text: t('screens.list.offline'), tone: 'red' }
                        : undefined,
                at: row.last_seen_at as string,
            };
        case 'external_json':
            return {
                main: String(row.label ?? ''),
                tag:
                    row.value !== null && row.value !== undefined
                        ? { text: formatValue(row.value, 'number'), tone }
                        : undefined,
            };
        default:
            return { main: JSON.stringify(row) };
    }
}

function List({ widget, schema, data, now, t }: BodyProps) {
    const opt = <T,>(key: string) => option<T>(schema, widget, key);
    const rows = (data.rows as Row[]) ?? [];
    const wrap = opt<boolean>('wrap') === true;
    // One column wide: the key and the value matter, count and age do not fit.
    const narrow = widget.w <= 1;
    const scroller = useAutoScroll<HTMLUListElement>(
        opt<boolean>('auto_scroll') !== false && data.source !== 'hosts',
    );

    if (rows.length === 0) {
        return <span className="lj-tile-small">{t('screens.list.empty')}</span>;
    }

    if (data.source === 'hosts') {
        return (
            <Hosts
                rows={rows}
                t={t}
                scroll={opt<boolean>('auto_scroll') !== false}
            />
        );
    }

    const texts = rows.map((row) =>
        rowText(String(data.source), row, t, narrow),
    );
    const showCount = !narrow && opt<boolean>('show_count') !== false;
    const showAge = !narrow && opt<boolean>('show_age') !== false;
    // One grid for the whole list, each row a subgrid, so keys, tags, counts and ages line up.
    const columns = {
        key: texts.some((x) => x.key),
        tag: texts.some((x) => x.tag),
        count: showCount && texts.some((x) => x.count),
        age: showAge && texts.some((x) => x.at),
    };
    const template = [
        columns.key
            ? narrow
                ? 'minmax(0, max-content)'
                : 'minmax(0, 6.5em)'
            : null,
        'minmax(0, 1fr)',
        columns.tag ? 'max-content' : null,
        columns.count ? 'max-content' : null,
        columns.age ? 'max-content' : null,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <ul
            ref={scroller}
            className="grid min-h-0 w-full flex-1 content-start gap-x-[0.8em] overflow-hidden"
            style={{ gridTemplateColumns: template }}
        >
            {rows.map((row, i) => {
                const text = texts[i];

                return (
                    <li
                        key={i}
                        className="col-span-full grid grid-cols-subgrid items-baseline border-t border-(--s-line) py-[0.35em] first:border-t-0"
                    >
                        {columns.key && (
                            <span
                                className="truncate font-mono text-(--s-ink-2)"
                                // A symbol always shows its state; a name only when it needs attention.
                                style={{
                                    color:
                                        (text.key ?? '').length <= 1 ||
                                        row.state === 'red' ||
                                        row.state === 'amber'
                                            ? (ROW_COLOR[row.state ?? 'ok'] ??
                                              undefined)
                                            : undefined,
                                }}
                            >
                                {text.key}
                            </span>
                        )}
                        <span className={cn('min-w-0', !wrap && 'truncate')}>
                            {opt<boolean>('show_project') && row.project && (
                                <span className="mr-[0.5em] text-(--s-ink-3)">
                                    {row.project}
                                </span>
                            )}
                            {text.main}
                        </span>
                        {columns.tag && (
                            <span
                                className="text-right text-[0.85em] font-semibold whitespace-nowrap"
                                style={{
                                    color:
                                        ROW_COLOR[text.tag?.tone ?? ''] ??
                                        'var(--s-ink-2)',
                                }}
                            >
                                {text.tag?.text}
                            </span>
                        )}
                        {columns.count && (
                            <span className="text-right font-mono text-[0.85em] text-(--s-ink-3)">
                                {text.count}
                            </span>
                        )}
                        {columns.age && (
                            <span className="text-right font-mono text-[0.85em] whitespace-nowrap text-(--s-ink-3)">
                                {text.at ? lasted(text.at, now) : ''}
                            </span>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

/** Hosts: each name over three small meters, CPU, memory and disk, colored by limit. */
function Hosts({
    rows,
    t,
    scroll,
}: {
    rows: Row[];
    t: Translate;
    scroll: boolean;
}) {
    const scroller = useAutoScroll<HTMLUListElement>(scroll);
    const meters = [
        ['cpu', 'cpu_load'],
        ['memory', 'memory_used'],
        ['disk', 'disk_used'],
    ] as const;
    const tone = (v: number) =>
        v >= 0.9
            ? 'var(--s-red)'
            : v >= 0.8
              ? 'var(--s-amber)'
              : 'var(--s-blue)';

    return (
        <ul
            ref={scroller}
            className="flex min-h-0 w-full flex-1 flex-col gap-[0.45em] overflow-hidden"
        >
            {rows.map((row, i) => (
                <li
                    key={i}
                    className="flex flex-col gap-[0.2em] border-t border-(--s-line) pt-[0.35em] first:border-t-0 first:pt-0"
                >
                    <div className="flex items-baseline justify-between gap-[0.6em]">
                        <span
                            className="min-w-0 truncate font-mono"
                            style={{
                                color:
                                    row.state === 'red' || row.state === 'amber'
                                        ? ROW_COLOR[row.state]
                                        : undefined,
                            }}
                        >
                            {String(row.host)}
                        </span>
                        {row.online === false && (
                            <span className="flex-none text-[0.85em] font-semibold text-(--s-red)">
                                {t('screens.list.offline')}
                            </span>
                        )}
                    </div>
                    {row.online !== false && (
                        <div className="grid grid-cols-3 gap-[0.5em]">
                            {meters.map(([label, key]) => {
                                const v = row[key] as number | null | undefined;

                                return (
                                    <div
                                        key={key}
                                        className="flex min-w-0 flex-col gap-[0.15em]"
                                    >
                                        <span className="lj-tile-small flex justify-between gap-[0.3em] whitespace-nowrap">
                                            <span className="truncate">
                                                {t(
                                                    `screens.list.meter.${label}`,
                                                )}
                                            </span>
                                            <span className="font-mono">
                                                {v === null || v === undefined
                                                    ? '-'
                                                    : formatPercent(v, 0)}
                                            </span>
                                        </span>
                                        <span className="h-[0.3em] overflow-hidden rounded-full bg-(--s-line)">
                                            <span
                                                className="block h-full rounded-full"
                                                style={{
                                                    width: `${Math.round(Math.min(1, v ?? 0) * 100)}%`,
                                                    background: tone(v ?? 0),
                                                }}
                                            />
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

type Check = {
    uuid: string;
    name: string;
    state: string;
    message: string | null;
    since: string | null;
};

function Health({ widget, schema, data, now, t }: BodyProps) {
    const opt = <T,>(key: string) => option<T>(schema, widget, key);
    const [ref, size] = useSize<HTMLDivElement>();
    const staleAs = opt<string>('stale_as') ?? 'stale';
    const fix = (state: string) => (state === 'stale' ? staleAs : state);
    const checks = ((data.checks as Check[]) ?? []).map((c) => ({
        ...c,
        state: fix(c.state),
    }));
    const summary = data.summary as Record<string, number>;
    const detail = data.check as
        | (Check & {
              last_message: string | null;
              uptime: number | null;
              strip: { t: string; s: string | null }[];
              metrics?: Record<string, number>;
          })
        | null;
    // Cards keep a readable minimum width (140px, more on a big TV).
    const cardCols = Math.max(
        1,
        Math.floor((size.w + 6) / (Math.max(140, 9 * size.em) + 6)),
    );
    const view =
        (opt<string>('view') ?? 'auto') !== 'auto'
            ? opt<string>('view')
            : widget.w * widget.h <= 1
              ? 'summary'
              : cardCols >= 2
                ? 'cards'
                : 'list';
    const word = (state: string) => t(`screens.states.${state}`);

    if (detail) {
        const state = fix(detail.state);

        return (
            <>
                <span
                    className="lj-tile-big"
                    style={{
                        // Leaves room for the lines under it on a one row tile.
                        fontSize: 'min(22cqh, 15cqw)',
                        color: STATE_COLOR[state],
                    }}
                >
                    {word(state)}
                </span>
                {/* The lines under the state sit close, so a 1 x 1 tile fits them. */}
                <div className="flex max-w-full min-w-0 shrink-0 flex-col [align-items:inherit]">
                    <span
                        className="lj-tile-small max-w-full shrink-0 truncate text-[0.68em] leading-[1.15]"
                        style={{ color: STATE_COLOR[state] }}
                    >
                        {[
                            detail.since
                                ? t('screens.health.for', {
                                      state: word(state),
                                      time: lasted(detail.since, now),
                                  })
                                : word(state),
                            opt<boolean>('show_uptime') !== false &&
                            detail.uptime !== null
                                ? t('screens.health.uptime', {
                                      uptime: formatPercent(detail.uptime, 2),
                                  })
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                    {/* One line under the state, kept even when empty so tiles line up: the
                    database's figures when it reports them, the last message otherwise. */}
                    {opt<boolean>('show_message') !== false &&
                        (hasDatabaseFigures(detail.metrics) &&
                        !detail.last_message ? (
                            <DatabaseLine metrics={detail.metrics ?? {}} />
                        ) : (
                            <span className="lj-tile-small max-w-full shrink-0 truncate text-[0.68em] leading-[1.15]">
                                {detail.last_message || '\u00a0'}
                            </span>
                        ))}
                </div>
                {opt<boolean>('show_timeline') !== false &&
                    widget.h >= 2 &&
                    detail.strip.length > 0 && (
                        <div
                            aria-hidden="true"
                            className="mt-auto flex h-[1.2em] gap-[2px]"
                        >
                            {detail.strip.map((seg, i) => (
                                <span
                                    key={i}
                                    className="flex-1 rounded-[2px]"
                                    style={{
                                        background: seg.s
                                            ? STATE_COLOR[fix(seg.s)]
                                            : 'var(--s-line)',
                                    }}
                                />
                            ))}
                        </div>
                    )}
            </>
        );
    }

    if (view === 'summary') {
        const worst = checks.find((c) => c.state !== 'ok');

        return (
            <>
                <span
                    className="lj-tile-big"
                    style={{
                        fontSize: 'min(30cqh, 14cqw)',
                        color:
                            summary.down > 0
                                ? 'var(--s-red)'
                                : worst
                                  ? 'var(--s-amber)'
                                  : 'var(--s-green)',
                    }}
                >
                    {t('screens.health.ok_of', {
                        ok: summary.ok,
                        total: summary.total,
                    })}
                </span>
                <span
                    className="lj-tile-small truncate"
                    style={{
                        color: worst ? STATE_COLOR[worst.state] : undefined,
                    }}
                >
                    {worst
                        ? `${worst.name} ${word(worst.state).toLowerCase()}`
                        : t('screens.health.all_pass')}
                </span>
            </>
        );
    }

    const rowPx = size.em * (view === 'cards' ? 2.9 : 1.7);
    const perRow = view === 'cards' ? cardCols : 1;
    const fit = Math.max(1, perRow * Math.floor((size.h + 6) / (rowPx + 6)));
    const more = checks.length > fit ? checks.length - fit + 1 : 0;
    const visible = checks.slice(0, more ? fit - 1 : fit);

    return (
        <div ref={ref} className="min-h-0 w-full flex-1 overflow-hidden">
            {checks.length === 0 && (
                <span
                    className="lj-tile-small"
                    style={{ color: 'var(--s-green)' }}
                >
                    {t('screens.health.every_passes')}
                </span>
            )}
            {view === 'cards' ? (
                <div className="grid grid-cols-[repeat(auto-fill,minmax(max(140px,9em),1fr))] content-start gap-1.5">
                    {visible.map((c) => (
                        <div
                            key={c.uuid}
                            title={c.name}
                            className="flex h-[2.9em] min-w-0 flex-col justify-center rounded-md border border-l-[3px] px-2"
                            style={{
                                background:
                                    CARD_BG[c.state] ?? 'var(--s-panel-2)',
                                borderColor:
                                    c.state === 'ok'
                                        ? 'var(--s-green-line)'
                                        : STATE_COLOR[c.state],
                                borderLeftColor: STATE_COLOR[c.state],
                            }}
                        >
                            <span className="truncate font-mono text-[0.8em]">
                                {c.name}
                            </span>
                            <span
                                className="truncate text-[0.72em]"
                                style={{
                                    color:
                                        c.state === 'ok'
                                            ? 'var(--s-ink-2)'
                                            : STATE_COLOR[c.state],
                                }}
                            >
                                {[
                                    word(c.state),
                                    c.since ? lasted(c.since, now) : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </div>
                    ))}
                    {more > 0 && (
                        <div className="flex h-[2.9em] items-center rounded-md border border-(--s-line) bg-(--s-panel-2) px-2 text-[0.8em] text-(--s-ink-2)">
                            {t('screens.health.more', { count: more })}
                        </div>
                    )}
                </div>
            ) : (
                <ul className="flex flex-col">
                    {visible.map((c) => (
                        <li
                            key={c.uuid}
                            className="flex h-[1.7em] items-center truncate text-[0.85em]"
                            style={{ color: STATE_COLOR[c.state] }}
                        >
                            {'● ' +
                                c.name +
                                ' · ' +
                                (c.since
                                    ? t('screens.health.for', {
                                          state: word(c.state),
                                          time: lasted(c.since, now),
                                      })
                                    : word(c.state))}
                        </li>
                    ))}
                    {more > 0 && (
                        <li className="flex h-[1.7em] items-center text-[0.85em] text-(--s-ink-2)">
                            {t('screens.health.more', { count: more })}
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}

function Clock({
    widget,
    schema,
    now,
    screenZone,
}: {
    widget: Widget;
    schema: Schema;
    now: number;
    screenZone: string | null;
}) {
    const opt = <T,>(key: string) => option<T>(schema, widget, key);
    const mode = opt<string>('mode') ?? 'clock';
    // Its own zone, else the screen's, else the browser's.
    const timeZone = opt<string>('timezone') || screenZone || undefined;
    const lang = document.documentElement.lang || 'en';
    const time = new Intl.DateTimeFormat(lang, {
        timeZone,
        hour: '2-digit',
        minute: '2-digit',
        second: opt<boolean>('seconds') ? '2-digit' : undefined,
        hourCycle: opt<string>('format') === '12' ? 'h12' : 'h23',
    }).format(new Date(now));
    const date = new Intl.DateTimeFormat(lang, {
        timeZone,
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date(now));
    const text = opt<string>('text');
    const size: CSSProperties = {
        fontSize: BIG_SIZE[opt<string>('text_size') ?? 'auto'],
    };

    return (
        <>
            {mode !== 'text' && (
                <span className="lj-tile-big" style={size}>
                    {time}
                </span>
            )}
            {opt<boolean>('date') && (
                <span className="lj-tile-small">{date}</span>
            )}
            {mode !== 'clock' && text && (
                <span
                    className={mode === 'text' ? 'font-semibold' : ''}
                    style={mode === 'text' ? size : undefined}
                >
                    {text}
                </span>
            )}
        </>
    );
}
