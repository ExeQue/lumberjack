import { router, usePage } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';

export const DEFAULT_INTERVAL_MS = 2_000;

export type LiveOptions = {
    /** Ignore events for now, without unsubscribing. */
    paused?: boolean;
    /**
     * The page has forms filled from the props being reloaded. While the user has typed
     * into one, a change elsewhere is not loaded but announced (`changedElsewhere`), so
     * unsaved edits are never replaced under their hands.
     */
    guard?: boolean;
};

export type LiveReload = {
    /** Ask for a reload, at most one per `interval` milliseconds. */
    schedule: (interval?: number) => void;
    /** Something changed while the user was editing; see `LiveOptions.guard`. */
    changedElsewhere: boolean;
    /** Load the page afresh, dropping unsaved edits. */
    reloadFresh: () => void;
};

// Whether the user has typed into the page since it last loaded or saved.
let editing = false;
let tracking = false;

function trackEditing(): void {
    if (tracking) {
        return;
    }

    tracking = true;
    const mark = (selector: string) => (event: Event) => {
        if (
            event.target instanceof Element &&
            !event.target.closest('[cmdk-root]') &&
            event.target.closest(selector)
        ) {
            editing = true;
        }
    };
    document.addEventListener(
        'input',
        mark(
            'main input, main textarea, main select, form input, form textarea, form select',
        ),
        true,
    );
    // Switches are buttons, not inputs.
    document.addEventListener(
        'click',
        mark('main [role=switch], form [role=switch]'),
        true,
    );
    // Saving something, or going to another page, ends the edit.
    router.on('start', (event) => {
        const visit = event.detail.visit;

        if (visit.method === 'get' && !visit.async) {
            editing = false;
        }
    });
    router.on('finish', (event) => {
        if (event.detail.visit.method !== 'get') {
            editing = false;
        }
    });
}

/**
 * Reloads the given props when asked to by a live event. At most one reload runs at a
 * time: requests during a reload, or sooner than the interval after the last one, coalesce
 * into one trailing reload. A hidden tab does not reload at all; it reloads once when it
 * becomes visible again.
 */
export function useLiveReload(
    only: string[],
    options: LiveOptions = {},
): LiveReload {
    const only_ = only.join(',');
    const onlyRef = useRef(only);
    const guardRef = useRef(options.guard ?? false);
    const pausedRef = useRef(options.paused ?? false);
    const scheduleRef = useRef<(interval: number) => void>(() => {});
    const [changedElsewhere, setChangedElsewhere] = useState(false);

    useEffect(() => {
        onlyRef.current = only_.split(',');
        guardRef.current = options.guard ?? false;
        pausedRef.current = options.paused ?? false;
    }, [only_, options.guard, options.paused]);

    useEffect(() => {
        if (options.guard) {
            trackEditing();
        }
    }, [options.guard]);

    useEffect(() => {
        let inFlight = false;
        let last = 0;
        let stale = false;
        let timer: ReturnType<typeof setTimeout> | null = null;
        // The shortest minimum interval among the requests waiting for the next reload.
        let interval = Infinity;

        const reload = () => {
            if (document.hidden) {
                stale = true;

                return;
            }

            stale = false;

            if (guardRef.current && editing) {
                setChangedElsewhere(true);

                return;
            }

            inFlight = true;
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
            if (pausedRef.current) {
                return;
            }

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

        // A save brings the whole page back, so what changed elsewhere is in it.
        const stopFinish = router.on('finish', (event) => {
            if (event.detail.visit.method !== 'get') {
                setChangedElsewhere(false);
            }
        });

        scheduleRef.current = schedule;
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            scheduleRef.current = () => {};
            stopFinish();
            document.removeEventListener('visibilitychange', onVisible);

            if (timer !== null) {
                clearTimeout(timer);
            }
        };
    }, []);

    return {
        schedule: (interval = DEFAULT_INTERVAL_MS) =>
            scheduleRef.current(interval),
        changedElsewhere,
        reloadFresh: () => {
            editing = false;
            setChangedElsewhere(false);
            router.visit(window.location.href, {
                preserveState: false,
                preserveScroll: true,
                replace: true,
            });
        },
    };
}

export type TeamLiveKind =
    | 'members.changed'
    | 'invitations.changed'
    | 'tokens.changed'
    | 'alerts.changed'
    | 'retention.changed'
    | 'projects.changed';

export type AdminLiveKind =
    | 'teams.changed'
    | 'users.changed'
    | 'audit.changed'
    | 'retention.changed';

/** Listens on a private channel and asks `live` for a reload on any of the events. */
export function useLiveChannel(
    channel: string,
    events: string[],
    live: LiveReload,
): void {
    useEcho(
        channel,
        events.map((event) => `.${event}`),
        () => live.schedule(),
        [channel, events.join(',')],
    );
}

/** Reloads the props when the current team's channel says one of the kinds changed. */
export function useTeamLive(
    kinds: TeamLiveKind[],
    only: string[],
    options: LiveOptions = {},
): LiveReload {
    const team = usePage<{ team: { uuid: string } | null }>().props.team;
    const live = useLiveReload(only, options);
    useLiveChannel(`team.${team?.uuid ?? 'none'}`, kinds, live);

    return live;
}

/** Reloads the props when the administrators' channel says one of the kinds changed. */
export function useAdminLive(
    kinds: AdminLiveKind[],
    only: string[],
    options: LiveOptions = {},
): LiveReload {
    const live = useLiveReload(only, options);
    useLiveChannel('admin', kinds, live);

    return live;
}

/**
 * Keeps a form filled from props in step with them after a live reload, unless the
 * user has changed it: their edits stay until they save or reload.
 */
export function useFormSync<T extends Record<string, unknown>>(
    form: InertiaFormProps<T>,
    fresh: T,
): void {
    const key = JSON.stringify(fresh);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        // Saved as typed is not an edit either.
        if (!form.isDirty || JSON.stringify(form.data) === key) {
            form.setDefaults(fresh);
            form.setData(fresh);
        }
    }, [key]);
}
