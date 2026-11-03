import { useEffect, useRef, useState } from 'react';
import type {
    ComponentProps,
    CSSProperties,
    ReactNode,
    RefObject,
} from 'react';
import { formatPercent } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    formatValue,
    incident as incidentPreview,
    option,
    rotationIndex,
    sourcesOf,
    statusOf,
} from './lib';
import type { Schema, Sources, Status, Widget, WidgetData } from './types';

type Payload = Record<string, unknown>;
type Translate = ReturnType<typeof useT>;

/** A translation, or the fallback when the key has none. */
function orKey(t: Translate, key: string, fallback: string): string {
    const text = t(key);

    return text === key ? fallback : text;
}

/** Status words and colors per check state, worst first. */
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
function lasted(iso: string | null | undefined, now: number): string {
    return iso
        ? duration(Math.max(0, (now - new Date(iso).getTime()) / 1000))
        : '';
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

export type TileProps = {
    widget: Widget;
    schema: Schema;
    sources: Sources;
    data: WidgetData | undefined;
    now: number;
    /** The designer pauses rotation on the selected tile. */
    paused?: boolean;
    incident?: boolean;
    designer?: boolean;
    flash?: boolean;
    /** Extra content above the tile's own: the designer's badge and resize handle. */
    decoration?: ReactNode;
    /** Told the tile's status whenever it changes, for the kiosk's flash and count. */
    onStatus?: (id: string, status: Status) => void;
} & Omit<ComponentProps<'div'>, 'children'>;

/**
 * One tile of a screen, the same in the designer and on the TV. Its type draws the data
 * of the source it shows now; its state colors it.
 */
export function WidgetTile({
    widget,
    schema,
    sources,
    data,
    now,
    paused = false,
    incident = false,
    designer = false,
    flash = false,
    decoration,
    onStatus,
    className,
    style,
    ...props
}: TileProps) {
    const t = useT();
    const opt = <T,>(key: string) => option<T>(schema, widget, key);
    const shown = sourcesOf(widget);
    const preview = (id: string) =>
        incident ? incidentPreview(widget.type, data?.[id]) : data?.[id];
    const previewData: WidgetData | undefined = data
        ? Object.fromEntries(shown.map((id) => [id, preview(id) ?? null]))
        : undefined;
    const index = paused
        ? 0
        : rotationIndex(
              widget.type,
              shown,
              previewData,
              Number(opt<string>('rotate_every') ?? 10),
              opt<boolean>('hold_problem') !== false,
              now,
          );
    const sourceId = shown[index] ?? null;
    const payload = sourceId ? previewData?.[sourceId] : undefined;
    const source = sources[widget.type]?.find((s) => s.id === sourceId);
    const colored = opt<boolean>('status_colors') !== false;
    const status: Status = colored ? statusOf(widget.type, payload) : null;
    const title =
        shown.length > 1
            ? (source?.label ?? '')
            : (widget.title ??
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
    const transition = opt<string>('transition') ?? 'fade';

    let body: ReactNode = null;

    if (widget.type === 'empty') {
        body = designer ? (
            <span className="m-auto text-[0.8em] text-(--s-ink-4)">
                {t('screens.kinds.empty')}
            </span>
        ) : null;
    } else if (widget.type === 'clock') {
        body = <Clock widget={widget} schema={schema} now={now} />;
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
            data-status={status ?? undefined}
            data-flash={flash || undefined}
            style={{
                gridColumn: `span ${widget.w}`,
                gridRow: `span ${widget.h}`,
                textAlign: align,
                ['--lj-text-scale' as string]: TEXT_SCALE[textSize] ?? 1,
                visibility: hidden && !designer ? 'hidden' : undefined,
                opacity: hidden && designer ? 0.45 : undefined,
                ...style,
            }}
        >
            {decoration}
            {showTitle && widget.type !== 'empty' && title && (
                <div
                    className={cn(
                        'flex items-baseline gap-[0.6em]',
                        align === 'center'
                            ? 'justify-center'
                            : 'justify-between',
                    )}
                >
                    <span className="lj-tile-title min-w-0 truncate">
                        {title}
                    </span>
                    {widget.type === 'list' && sourceId && (
                        <span className="lj-tile-small whitespace-nowrap">
                            {orKey(
                                t,
                                `screens.list.sort_hint.${sourceId}.${opt<string>('sort') ?? 'default'}`,
                                t(`screens.list.sort_hint.${sourceId}.default`),
                            )}
                        </span>
                    )}
                </div>
            )}
            <div
                key={sourceId ?? 'tile'}
                className={cn(
                    'flex min-h-0 flex-1 flex-col gap-[0.3em]',
                    shown.length > 1 &&
                        transition === 'fade' &&
                        'animate-in duration-500 fade-in',
                    shown.length > 1 &&
                        transition === 'slide' &&
                        'animate-in duration-500 slide-in-from-right-8',
                )}
                style={{
                    alignItems: align === 'center' ? 'center' : undefined,
                    justifyContent: middle ? 'center' : undefined,
                }}
            >
                {body}
            </div>
            {shown.length > 1 && opt<boolean>('dots') !== false && (
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-x-0 bottom-1.5 flex justify-center gap-1"
                >
                    {shown.map((id, i) => (
                        <span
                            key={id}
                            className="h-1 rounded-full transition-all"
                            style={{
                                width: i === index ? 14 : 4,
                                background:
                                    i === index
                                        ? 'var(--s-ink)'
                                        : 'var(--s-ink-4)',
                            }}
                        />
                    ))}
                </div>
            )}
        </div>
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
    t,
    textSize,
    colored,
}: BodyProps & { colored: boolean }) {
    const opt = <T,>(key: string) => option<T>(schema, widget, key);
    const compare = opt<string>('compare') ?? 'previous';
    const change = data.change as number | null;
    const spark = (data.sparkline as { v: number | null }[] | null) ?? null;
    const extra = (compare !== 'none' && change !== null) || !!spark;
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
    const za = limits?.amber !== null && limits ? fraction(limits.amber) : null;
    const zone =
        za === null
            ? `0 ${zl}`
            : limits.low_is_bad
              ? `${zl * za} ${zl}`
              : `0 ${zl * za} ${zl * (1 - za)} ${zl}`;
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
        <div className="relative min-h-0 flex-1">
            <svg
                viewBox="0 0 120 72"
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
                        strokeDasharray={zone}
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
                    y="71"
                    textAnchor="middle"
                    fill="var(--s-ink-3)"
                    style={{ fontSize: 7 }}
                >
                    {formatValue(min, unit)}
                </text>
                <text
                    x="110"
                    y="71"
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
    const top = Math.max(1e-9, ...values, red ?? 0) * 1.05;
    const bottom =
        opt<boolean>('y_zero') !== false ? 0 : Math.min(...values, top) * 0.95;
    const points = series[0]?.points ?? [];
    const n = Math.max(1, points.length);
    const y = (v: number | null) =>
        40 - ((Math.max(bottom, v ?? 0) - bottom) / (top - bottom || 1)) * 40;
    const x = (i: number) => ((i + 0.5) / n) * 100;
    const line = (s: Series) =>
        s.points.map((p, i) => `${x(i)},${y(p.v)}`).join(' ');
    const label = (iso: string | undefined) =>
        iso ? timeOf(iso, !String(data.interval).match(/[ms]$/)) : '';
    const total = series.reduce(
        (sum, s) => sum + s.points.reduce((a, p) => a + (p.v ?? 0), 0),
        0,
    );

    return (
        <>
            <div className="relative min-h-0 flex-1">
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
                                            height={Math.max(40 - y(err), 1.2)}
                                            rx="0.4"
                                            fill="var(--s-red)"
                                        />
                                    )}
                                </g>
                            );
                        })}
                    {style === 'area' && series[0] && (
                        <polygon
                            points={`${x(0)},40 ${line(series[0])} ${x(n - 1)},40`}
                            fill={seriesColor(String(data.source), 0, false)}
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
                                <polyline
                                    key={s.name}
                                    points={line(s)}
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
                        <polyline
                            points={line(previous)}
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
            {opt<boolean>('axis') !== false && points.length > 1 && (
                <div className="lj-tile-small flex justify-between font-mono">
                    <span>{label(points[0]?.t)}</span>
                    <span>
                        {label(points[Math.floor(points.length / 2)]?.t)}
                    </span>
                    <span>{label(points[points.length - 1]?.t)}</span>
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

function rowText(source: string, row: Row, t: Translate): RowText {
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
                count:
                    row.oldest_age_s !== null && row.oldest_age_s !== undefined
                        ? t('screens.list.oldest', {
                              age: duration(Number(row.oldest_age_s)),
                          })
                        : undefined,
            };
        case 'hosts':
            return {
                key: String(row.host),
                main: t('screens.list.host_usage', {
                    cpu: percent(row.cpu_load),
                    memory: percent(row.memory_used),
                    disk: percent(row.disk_used),
                }),
            };
        case 'agents':
            return {
                key: '●',
                main: [row.host, row.name].filter(Boolean).join(' · '),
                at: row.last_seen_at as string,
            };
        default:
            return { main: JSON.stringify(row) };
    }
}

function List({ widget, schema, data, now, t }: BodyProps) {
    const opt = <T,>(key: string) => option<T>(schema, widget, key);
    const rows = (data.rows as Row[]) ?? [];
    const wrap = opt<boolean>('wrap') === true;

    if (rows.length === 0) {
        return <span className="lj-tile-small">{t('screens.list.empty')}</span>;
    }

    return (
        <ul className="flex min-h-0 flex-1 flex-col overflow-hidden">
            {rows.map((row, i) => {
                const text = rowText(String(data.source), row, t);

                return (
                    <li
                        key={i}
                        className="flex items-baseline gap-[0.8em] border-t border-(--s-line) py-[0.35em] first:border-t-0"
                    >
                        {text.key && (
                            <span
                                className="w-[6.5em] flex-none truncate font-mono text-(--s-ink-2)"
                                style={{
                                    color:
                                        text.key.length <= 1
                                            ? (ROW_COLOR[row.state ?? 'ok'] ??
                                              undefined)
                                            : undefined,
                                }}
                            >
                                {text.key}
                            </span>
                        )}
                        <span
                            className={cn(
                                'min-w-0 flex-1',
                                !wrap && 'truncate',
                            )}
                        >
                            {opt<boolean>('show_project') && row.project && (
                                <span className="mr-[0.5em] text-(--s-ink-3)">
                                    {row.project}
                                </span>
                            )}
                            {text.main}
                        </span>
                        {text.tag && (
                            <span
                                className="flex-none text-[0.85em] font-semibold whitespace-nowrap"
                                style={{
                                    color:
                                        ROW_COLOR[text.tag.tone ?? ''] ??
                                        'var(--s-ink-2)',
                                }}
                            >
                                {text.tag.text}
                            </span>
                        )}
                        {opt<boolean>('show_count') !== false && text.count && (
                            <span className="flex-none font-mono text-[0.85em] text-(--s-ink-3)">
                                {text.count}
                            </span>
                        )}
                        {opt<boolean>('show_age') !== false && text.at && (
                            <span className="flex-none font-mono text-[0.85em] whitespace-nowrap text-(--s-ink-3)">
                                {lasted(text.at, now)}
                            </span>
                        )}
                    </li>
                );
            })}
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
                        fontSize: 'min(30cqh, 15cqw)',
                        color: STATE_COLOR[state],
                    }}
                >
                    {word(state)}
                </span>
                <span
                    className="lj-tile-small"
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
                {opt<boolean>('show_message') !== false &&
                    detail.last_message && (
                        <span className="lj-tile-small truncate">
                            {detail.last_message}
                        </span>
                    )}
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
        <div ref={ref} className="min-h-0 flex-1 overflow-hidden">
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
}: {
    widget: Widget;
    schema: Schema;
    now: number;
}) {
    const opt = <T,>(key: string) => option<T>(schema, widget, key);
    const mode = opt<string>('mode') ?? 'clock';
    const timeZone = opt<string>('timezone') || undefined;
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
