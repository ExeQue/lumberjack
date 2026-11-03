import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

export type LiveKind =
    | 'issues.changed'
    | 'jobs.changed'
    | 'schedule.changed'
    | 'commands.changed'
    | 'logs.changed'
    | 'performance.changed'
    | 'agents.changed'
    | 'health.changed'
    | 'issue.updated';

/**
 * Milliseconds between two reloads caused by one kind. Performance changes with every
 * request a busy project serves, and its statistics are the heaviest to compute.
 */
const MIN_INTERVAL_MS: Partial<Record<LiveKind, number>> = {
    'performance.changed': 10_000,
};
const DEFAULT_INTERVAL_MS = 2_000;

/**
 * Reloads the given props when the project's private channel says something changed.
 * The events carry no data; the page asks the server again.
 *
 * At most one reload runs at a time: events during a reload, or sooner than the kind's
 * minimum interval after the last one, coalesce into one trailing reload. A hidden tab
 * does not reload at all; it reloads once when it becomes visible again.
 *
 * With `issue` set, only events about that issue count: the project-wide `issues.changed`
 * (which names no issue) is for list pages.
 */
export function useProjectLive(
    projectUuid: string,
    kinds: LiveKind[],
    only: string[],
    options: { paused?: boolean; issue?: string } = {},
): void {
    const only_ = only.join(',');
    const onlyRef = useRef(only);
    const scheduleRef = useRef<(interval: number) => void>(() => {});

    useEffect(() => {
        onlyRef.current = only_.split(',');
    }, [only_]);

    useEffect(() => {
        let inFlight = false;
        let last = 0;
        let stale = false;
        let timer: ReturnType<typeof setTimeout> | null = null;
        // The shortest minimum interval among the kinds waiting for the next reload.
        let interval = Infinity;

        const reload = () => {
            if (document.hidden) {
                stale = true;

                return;
            }

            inFlight = true;
            stale = false;
            last = Date.now();
            router.reload({
                only: onlyRef.current,
                preserveUrl: true,
                onFinish: () => {
                    inFlight = false;

                    if (stale) {
                        schedule(interval);
                    }
                },
            } as Parameters<typeof router.reload>[0]);
        };

        const schedule = (wanted: number) => {
            stale = true;
            interval = Math.min(interval, wanted);

            if (inFlight || timer !== null) {
                return;
            }

            timer = setTimeout(
                () => {
                    timer = null;
                    interval = Infinity;
                    reload();
                },
                Math.max(0, last + interval - Date.now()),
            );
        };

        const onVisible = () => {
            if (!document.hidden && stale) {
                schedule(0);
            }
        };

        scheduleRef.current = schedule;
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            scheduleRef.current = () => {};
            document.removeEventListener('visibilitychange', onVisible);

            if (timer !== null) {
                clearTimeout(timer);
            }
        };
    }, []);

    useEcho<{ issue: string | null; kind?: LiveKind }>(
        `project.${projectUuid}`,
        kinds.map((kind) => `.${kind}`),
        (payload) => {
            if (options.paused) {
                return;
            }

            if (
                options.issue !== undefined &&
                payload?.issue !== options.issue
            ) {
                return;
            }

            const kind: LiveKind | undefined = payload?.issue
                ? 'issue.updated'
                : payload?.kind;

            scheduleRef.current(
                (kind && MIN_INTERVAL_MS[kind]) ?? DEFAULT_INTERVAL_MS,
            );
        },
        [projectUuid, options.paused, options.issue, only_],
    );
}
