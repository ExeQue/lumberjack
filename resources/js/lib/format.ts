const locale = (): string =>
    (document.documentElement.lang || 'en').replace('_', '-');

export function formatNumber(value: number, fractionDigits = 0): string {
    return new Intl.NumberFormat(locale(), {
        maximumFractionDigits: fractionDigits,
        minimumFractionDigits: fractionDigits,
    }).format(value);
}

export function formatCompact(value: number): string {
    return new Intl.NumberFormat(locale(), {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}

export function formatPercent(ratio: number, fractionDigits = 1): string {
    // Small shares get one more digit, so 0.4 % does not read as 0 %.
    const minimum =
        ratio > 0 && ratio < 0.1 ? Math.min(2, fractionDigits + 1) : 0;

    return new Intl.NumberFormat(locale(), {
        style: 'percent',
        minimumFractionDigits: minimum,
        maximumFractionDigits: Math.max(fractionDigits, minimum),
    }).format(ratio);
}

/**
 * Durations arrive in microseconds.
 */
export function formatDuration(micros: number | null | undefined): string {
    if (micros === null || micros === undefined) {
        return '-';
    }

    if (micros < 1000) {
        return `${formatNumber(micros)} µs`;
    }

    const ms = micros / 1000;

    if (ms < 1000) {
        return `${formatNumber(ms, ms < 10 ? 1 : 0)} ms`;
    }

    const s = ms / 1000;

    if (s < 60) {
        return `${formatNumber(s, s < 10 ? 2 : 1)} s`;
    }

    const minutes = Math.floor(s / 60);

    return `${minutes} min ${Math.round(s % 60)} s`;
}

export function formatRelative(
    iso: string | null | undefined,
    now: number = Date.now(),
): string {
    if (!iso) {
        return '-';
    }

    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const rtf = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' });
    const abs = Math.abs(seconds);

    if (abs < 60) {
        return rtf.format(seconds, 'second');
    }

    if (abs < 3600) {
        return rtf.format(Math.round(seconds / 60), 'minute');
    }

    if (abs < 86400) {
        return rtf.format(Math.round(seconds / 3600), 'hour');
    }

    return rtf.format(Math.round(seconds / 86400), 'day');
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '-';
    }

    return new Intl.DateTimeFormat(locale(), {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

export function formatTime(
    iso: string | null | undefined,
    withMillis = false,
): string {
    if (!iso) {
        return '-';
    }

    const date = new Date(iso);
    const base = new Intl.DateTimeFormat(locale(), {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(date);

    return withMillis
        ? `${base}.${String(date.getMilliseconds()).padStart(3, '0')}`
        : base;
}

/**
 * A log message with its PSR-3 placeholders ({order}) filled from the context, the way the
 * line reads once logged. Placeholders the context has no scalar for stay as they are.
 */
export function interpolateLogMessage(
    message: string,
    context: Record<string, unknown> | null | undefined,
): string {
    if (!context) {
        return message;
    }

    return message.replace(
        /\{([A-Za-z0-9_.]+)\}/g,
        (placeholder, key: string) => {
            const value = context[key];

            return typeof value === 'string' ||
                typeof value === 'number' ||
                typeof value === 'boolean'
                ? String(value)
                : placeholder;
        },
    );
}
