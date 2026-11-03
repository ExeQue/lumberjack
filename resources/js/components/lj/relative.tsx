import { useSyncExternalStore } from 'react';
import { formatRelative } from '@/lib/format';

/** How often "x ago" moves on; one shared timer for the whole page, so seconds count evenly. */
const TICK_MS = 1_000;

const listeners = new Set<() => void>();
let now = Date.now();
let timer: number | undefined;

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    if (timer === undefined) {
        timer = window.setInterval(() => {
            now = Date.now();
            listeners.forEach((l) => l());
        }, TICK_MS);
    }

    return () => {
        listeners.delete(listener);

        if (listeners.size === 0) {
            window.clearInterval(timer);
            timer = undefined;
        }
    };
}

/**
 * The current time, re-rendering the component every few seconds. Pass it to
 * formatRelative(iso, now): called without it, the React Compiler may reuse an old answer.
 */
export function useNow(): number {
    return useSyncExternalStore(
        subscribe,
        () => now,
        () => now,
    );
}

/**
 * "3 minutes ago" that keeps itself current.
 */
export function Relative({ iso }: { iso: string | null | undefined }) {
    // Passed in, not read inside: the React Compiler would keep the first answer for
    // the same iso if the time it depends on were not an argument.
    return formatRelative(iso, useNow());
}

/**
 * Text built around "3 minutes ago", kept current by this leaf alone, so the page around
 * it does not re-render every tick.
 */
export function WithRelative({
    iso,
    children,
}: {
    iso: string | null | undefined;
    children: (time: string) => string;
}) {
    return children(formatRelative(iso, useNow()));
}
