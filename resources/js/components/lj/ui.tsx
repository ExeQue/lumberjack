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
    tone?: 'err' | 'warn';
}) {
    return (
        <div className="flex flex-col gap-2 rounded-lg border border-line bg-panel p-4">
            <Eyebrow>{label}</Eyebrow>
            <span
                className={cn(
                    'font-mono text-2xl font-medium',
                    tone === 'err' && 'text-err',
                    tone === 'warn' && 'text-warn',
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
export const tdClass = 'border-b border-line/60 px-3 py-2.5 align-middle';
export const numClass = 'text-right font-mono tabular-nums whitespace-nowrap';

export function Sparkline({
    values,
    label,
    color = 'var(--lj-err)',
    width = 120,
    height = 28,
}: {
    values: number[];
    label: string;
    color?: string;
    width?: number;
    height?: number;
}) {
    const max = Math.max(1, ...values);
    const points = values
        .map(
            (v, i) =>
                `${(i / Math.max(1, values.length - 1)) * width},${height - 2 - (v / max) * (height - 4)}`,
        )
        .join(' ');

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            preserveAspectRatio="none"
            className="block"
            style={{ width: '100%', maxWidth: width, height }}
            role="img"
            aria-label={label}
        >
            <polyline
                points={points}
                fill="none"
                stroke={color}
                strokeWidth={1.5}
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}

export function Bars({
    values,
    label,
    color = 'var(--lj-line-strong)',
    height = 140,
    highlightNonZero,
}: {
    values: number[];
    label: string;
    color?: string;
    height?: number;
    highlightNonZero?: string;
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
                    className="flex-1 rounded-t-sm"
                    style={{
                        height: `${Math.max(2, (v / max) * 100)}%`,
                        background:
                            highlightNonZero && v > 0
                                ? highlightNonZero
                                : color,
                    }}
                />
            ))}
        </div>
    );
}
