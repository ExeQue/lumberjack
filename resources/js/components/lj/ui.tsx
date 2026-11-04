import { Link } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type Tone = 'neutral' | 'err' | 'warn' | 'ok' | 'info' | 'amber';

const toneClasses: Record<Tone, string> = {
    neutral: 'border-line-strong text-ink-2',
    err: 'border-err-line bg-err-bg text-err',
    warn: 'border-warn-line bg-warn-bg text-warn',
    ok: 'border-ok-line bg-ok-bg text-ok',
    info: 'border-info-line bg-info-bg text-info',
    amber: 'border-amber/50 bg-amber/10 text-amber',
};

export function Badge({
    tone = 'neutral',
    className,
    title,
    children,
}: {
    tone?: Tone;
    className?: string;
    title?: string;
    children: ReactNode;
}) {
    return (
        <span
            title={title}
            className={cn(
                'inline-flex h-5 items-center gap-1 rounded border px-1.5 text-[11px] font-medium whitespace-nowrap',
                toneClasses[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}

export function Card({
    className,
    children,
    ...props
}: ComponentProps<'section'>) {
    return (
        <section
            className={cn('rounded-lg border border-line bg-panel', className)}
            {...props}
        >
            {children}
        </section>
    );
}

export function CardHeader({
    title,
    id,
    aside,
    description,
}: {
    title: ReactNode;
    id?: string;
    aside?: ReactNode;
    description?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
            <div className="flex min-w-0 flex-col gap-0.5">
                <h2 id={id} className="text-[13px] font-semibold">
                    {title}
                </h2>
                {description && (
                    <p className="text-xs text-ink-3">{description}</p>
                )}
            </div>
            {aside}
        </div>
    );
}

type ButtonVariant = 'default' | 'primary' | 'danger' | 'ghost';

const buttonClasses: Record<ButtonVariant, string> = {
    default: 'border-line-strong bg-panel-2 text-ink hover:bg-line',
    primary: 'border-amber bg-amber text-amber-ink hover:brightness-110',
    danger: 'border-err-line bg-err-bg text-err hover:brightness-125',
    ghost: 'border-transparent bg-transparent text-ink-2 hover:bg-panel-2 hover:text-ink',
};

export function buttonClass(
    variant: ButtonVariant = 'default',
    className?: string,
): string {
    return cn(
        'inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border px-3 text-[13px] font-medium whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber disabled:cursor-not-allowed disabled:opacity-50',
        buttonClasses[variant],
        className,
    );
}

export function Button({
    variant = 'default',
    className,
    type = 'button',
    ...props
}: ComponentProps<'button'> & { variant?: ButtonVariant }) {
    return (
        <button
            type={type}
            className={buttonClass(variant, className)}
            {...props}
        />
    );
}

export function LinkButton({
    variant = 'default',
    className,
    ...props
}: ComponentProps<typeof Link> & { variant?: ButtonVariant }) {
    return <Link className={buttonClass(variant, className)} {...props} />;
}

export const inputClass =
    'h-8 rounded-md border border-line-strong bg-rail px-2.5 text-[13px] text-ink placeholder:text-ink-3 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-amber';

export function Input({ className, ...props }: ComponentProps<'input'>) {
    return <input className={cn(inputClass, className)} {...props} />;
}

export function Select({ className, ...props }: ComponentProps<'select'>) {
    return <select className={cn(inputClass, 'pr-7', className)} {...props} />;
}

export function Label({ className, ...props }: ComponentProps<'label'>) {
    return (
        <label
            className={cn('text-[13px] font-medium', className)}
            {...props}
        />
    );
}

export function Field({
    label,
    htmlFor,
    hint,
    error,
    children,
    className,
}: {
    label: ReactNode;
    htmlFor: string;
    hint?: ReactNode;
    error?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {error ? (
                <p className="text-xs text-err">{error}</p>
            ) : hint ? (
                <p className="text-xs text-ink-3">{hint}</p>
            ) : null}
        </div>
    );
}

export function SrOnly({ children }: { children: ReactNode }) {
    return <span className="sr-only">{children}</span>;
}

export function Mono({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return <span className={cn('font-mono', className)}>{children}</span>;
}

export function Eyebrow({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'text-[11px] font-medium tracking-wide text-ink-3 uppercase',
                className,
            )}
        >
            {children}
        </span>
    );
}

export function StatCard({
    label,
    value,
    note,
    tone,
}: {
    label: ReactNode;
    value: ReactNode;
    note?: ReactNode;
    tone?: 'err' | 'warn' | 'ok';
}) {
    return (
        <div className="flex flex-col gap-2 rounded-lg border border-line bg-panel p-4">
            <Eyebrow>{label}</Eyebrow>
            <span
                className={cn(
                    'font-mono text-2xl font-medium',
                    tone === 'err' && 'text-err',
                    tone === 'warn' && 'text-warn',
                    tone === 'ok' && 'text-ok',
                )}
            >
                {value}
            </span>
            {note && <span className="text-xs text-ink-2">{note}</span>}
        </div>
    );
}

export function EmptyState({
    title,
    children,
}: {
    title: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
            <p className="font-medium">{title}</p>
            {children && (
                <div className="max-w-md text-[13px] text-ink-2">
                    {children}
                </div>
            )}
        </div>
    );
}

export function PageTitle({
    title,
    description,
    actions,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-3">
            <div className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {title}
                </h1>
                {description && (
                    <p className="text-[13px] text-ink-2">{description}</p>
                )}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

export function Segmented<T extends string>({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: T;
    options: { value: T; label: ReactNode }[];
    onChange: (value: T) => void;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className="inline-flex overflow-hidden rounded-md border border-line-strong"
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={option.value === value}
                    onClick={() => onChange(option.value)}
                    className="h-[30px] cursor-pointer border-r border-line-strong bg-panel px-2.5 text-xs text-ink-2 last:border-r-0 hover:text-ink aria-pressed:bg-line aria-pressed:text-ink"
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

export const tableClass = 'w-full border-collapse text-[13px]';
export const thClass =
    'border-b border-line px-3 py-2.5 text-left text-[11px] font-medium tracking-wide whitespace-nowrap text-ink-3 uppercase';
/**
 * The chosen entry of a menu (on a .lj-nav-item, whose corners wait for the amber bar):
 * a quiet background and an amber bar the full height of its left side, drawn on its own
 * (not as a shadow, which would bend along the corner) with ends as round as it is wide. The text keeps its weight, so nothing moves when the
 * choice changes.
 */
export const navActiveClass =
    'lj-nav-active relative rounded-l-none bg-panel-2 text-ink before:absolute before:inset-y-0 before:left-0 before:w-[3px] before:rounded-full before:bg-amber';

export const tdClass = 'border-b border-line/60 px-3 py-2.5 align-middle';
export const numClass = 'text-right font-mono tabular-nums whitespace-nowrap';

export function Sparkline({
    values,
    label,
    color = 'var(--lj-err)',
    width = 120,
    height = 28,
    tips,
}: {
    values: number[];
    label: string;
    color?: string;
    width?: number;
    height?: number;
    /** One hover text per value. */
    tips?: string[];
}) {
    const max = Math.max(1, ...values);
    // Nothing happened: a quiet line, not one in the alarm color.
    const quiet = values.every((v) => v === 0);
    const points = values
        .map(
            (v, i) =>
                `${(i / Math.max(1, values.length - 1)) * width},${height - 2 - (v / max) * (height - 4)}`,
        )
        .join(' ');

    const step = 100 / Math.max(1, values.length - 1);

    return (
        <div className="relative" style={{ maxWidth: width }}>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                className="block"
                style={{ width: '100%', height }}
                role="img"
                aria-label={label}
            >
                <polyline
                    points={points}
                    fill="none"
                    stroke={quiet ? 'var(--lj-line-strong)' : color}
                    strokeWidth={1.5}
                    vectorEffect="non-scaling-stroke"
                />
            </svg>
            {tips && (
                <div className="absolute inset-0" aria-hidden="true">
                    {values.map((_, i) => (
                        <div
                            key={i}
                            className="group absolute inset-y-0 -translate-x-1/2"
                            style={{ left: `${i * step}%`, width: `${step}%` }}
                        >
                            <div className="absolute inset-y-0 left-1/2 hidden w-px bg-line-strong group-hover:block" />
                            <span className="pointer-events-none absolute top-0 left-1/2 z-10 hidden -translate-x-1/2 -translate-y-full rounded border border-line-strong bg-panel-2 px-2 py-1 font-mono text-[11px] whitespace-nowrap text-ink shadow-lg group-hover:block">
                                {tips[i]}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export function Bars({
    values,
    label,
    color = 'var(--lj-line-strong)',
    height = 140,
    highlightNonZero,
    tips,
}: {
    values: number[];
    label: string;
    color?: string;
    height?: number;
    highlightNonZero?: string;
    /** One line per bar, shown on hover: "10:00 PM · 15". */
    tips?: string[];
}) {
    const max = Math.max(1, ...values);

    return (
        <div
            role="img"
            aria-label={label}
            className="flex items-end gap-[3px]"
            style={{ height }}
        >
            {values.map((v, i) => (
                <div
                    key={i}
                    className="group relative flex h-full flex-1 items-end"
                >
                    <div
                        className="w-full rounded-t-sm group-hover:brightness-125"
                        style={{
                            height: `${Math.max(2, (v / max) * 100)}%`,
                            background:
                                highlightNonZero && v > 0
                                    ? highlightNonZero
                                    : color,
                        }}
                    />
                    {tips?.[i] && (
                        <span
                            className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded border border-line-strong bg-panel-2 px-2 py-1 font-mono text-[11px] whitespace-nowrap text-ink shadow-lg group-hover:block"
                            aria-hidden="true"
                        >
                            {tips[i]}
                        </span>
                    )}
                </div>
            ))}
        </div>
    );
}

/**
 * The time a bucket starts, the way the period reads: a time of day for 1h and 24h, a
 * date beyond.
 */
const periodMs: Record<string, number> = {
    '1h': 3_600_000,
    '24h': 86_400_000,
    '7d': 604_800_000,
    '30d': 2_592_000_000,
};

/** Hover texts for plain values in buckets over a period, the last one holding now. */
export function spreadTips(
    values: number[],
    period: string,
    formatValue: (v: number) => string = String,
): string[] {
    const span = periodMs[period] ?? periodMs['24h'];
    const step = span / Math.max(1, values.length - 1);
    // The last bucket is the one now falls in.
    const end = Math.floor(Date.now() / step) * step;

    return values.map(
        (v, i) =>
            `${bucketLabel(new Date(end - (values.length - 1 - i) * step).toISOString(), period)} · ${formatValue(v)}`,
    );
}

export function bucketLabel(iso: string, period: string): string {
    const lang = document.documentElement.lang || undefined;
    const date = new Date(iso);

    return period === '1h' || period === '24h'
        ? date.toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleDateString(lang, { day: 'numeric', month: 'short' });
}

/**
 * Bars over time with their axes: the highest value and 0 on the left, five times
 * underneath, and each bar's time and value on hover.
 */
export function TimeBars({
    series,
    period,
    label,
    color,
    highlightNonZero,
    height,
    formatValue = (v) => String(v),
}: {
    series: { t: string; v: number }[];
    period: string;
    label: string;
    color?: string;
    highlightNonZero?: string;
    height?: number;
    formatValue?: (v: number) => string;
}) {
    const values = series.map((p) => p.v);
    // ponytail: five evenly spaced ticks, enough for 1h to 30d buckets
    const ticks =
        series.length > 1
            ? [0, 0.25, 0.5, 0.75, 1].map(
                  (f) => series[Math.round(f * (series.length - 1))],
              )
            : series;

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex gap-2">
                <div
                    className="flex w-10 shrink-0 flex-col justify-between text-right font-mono text-[11px] text-ink-3"
                    aria-hidden="true"
                >
                    <span>{formatValue(Math.max(1, ...values))}</span>
                    <span>0</span>
                </div>
                <div className="min-w-0 flex-1">
                    <Bars
                        values={values}
                        color={color}
                        highlightNonZero={highlightNonZero}
                        height={height}
                        label={label}
                        tips={series.map(
                            (p) =>
                                `${bucketLabel(p.t, period)} · ${formatValue(p.v)}`,
                        )}
                    />
                </div>
            </div>
            <div
                className="ml-12 flex justify-between font-mono text-[11px] text-ink-3"
                aria-hidden="true"
            >
                {ticks.map((p, i) => (
                    <span key={i}>{bucketLabel(p.t, period)}</span>
                ))}
            </div>
        </div>
    );
}
