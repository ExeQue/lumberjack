import { echo } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';
import { dataKey, option, rotationOf } from './lib';
import type { Schema, Screen, ScreenData, Widget } from './types';
import { DATA_KINDS } from './types';

/** Seconds between polls; the fallback when live broadcasts do not arrive. */
const POLL_SECONDS = 60;

/** While the server is unreachable, how often to try again. */
const RETRY_SECONDS = 30;

/** At most one refetch per this many seconds caused by live project changes. */
const LIVE_MIN_SECONDS = 10;

const PROJECT_EVENTS = [
    '.issues.changed',
    '.jobs.changed',
    '.schedule.changed',
    '.performance.changed',
    '.agents.changed',
    '.health.changed',
    '.issue.updated',
];

class HttpError extends Error {
    constructor(public status: number) {
        super(`HTTP ${status}`);
    }
}

/** Answers that mean the screen is not there for this viewer any more, not that the server is down. */
const GONE = [401, 403, 404, 410];

async function load(url: string, widget?: string): Promise<ScreenData> {
    // The URL may already carry a query (a display showing screens in turn asks by ?screen=).
    const target = new URL(url, window.location.origin);

    if (widget) {
        target.searchParams.set('widget', widget);
    }

    const response = await fetch(target.toString(), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new HttpError(response.status);
    }

    return ((await response.json()) as { data: ScreenData }).data;
}

/**
 * The data of a screen's widgets, kept current: all of it once, then only the widgets
 * whose data settings changed, polled every minute (or five, per widget), sooner when
 * a project the screen shows broadcasts a change. When the server cannot be reached the
 * last data stays and offlineSince says since when.
 */
export function useScreenData(
    url: string,
    screen: Pick<Screen, 'widgets' | 'scope'>,
    schema: Schema,
    teamProjects: string[],
    options: {
        /** Refetch sooner when a project broadcasts; off for viewers without a session. */
        liveProjects?: boolean;
        /** The data URL answered that the screen is gone (unshared, display removed). */
        onGone?: () => void;
    } = {},
): { data: ScreenData; offlineSince: number | null; updatedAt: number | null } {
    const [data, setData] = useState<ScreenData>({});
    const [offlineSince, setOfflineSince] = useState<number | null>(null);
    const [updatedAt, setUpdatedAt] = useState<number | null>(null);
    const fetched = useRef<Record<string, string>>({});
    const widgetsRef = useRef<Widget[]>([]);
    const dataWidgets = screen.widgets.filter((w) =>
        DATA_KINDS.includes(w.type),
    );
    const keys = dataWidgets
        .map((w) => `${w.id}:${dataKey(schema, w)}`)
        .join('|');

    useEffect(() => {
        widgetsRef.current = dataWidgets;
    });

    const run = useRef<(ids?: string[]) => void>(() => {});
    const onGone = useRef(options.onGone);

    useEffect(() => {
        onGone.current = options.onGone;
    });

    useEffect(() => {
        run.current = (ids?: string[]) => {
            const all = widgetsRef.current;
            const wanted = ids ?? all.map((w) => w.id);
            const whole = !ids || wanted.length >= all.length;
            const requests = whole
                ? [load(url)]
                : wanted.map((id) => load(url, id));
            const sent = Object.fromEntries(
                all.map((w) => [w.id, dataKey(schema, w)]),
            );

            Promise.all(requests)
                .then((parts) => {
                    const merged = Object.assign({}, ...parts) as ScreenData;
                    setData((current) => {
                        const next: ScreenData = {};

                        // Keep what is still on the screen; replace what was asked again.
                        for (const w of widgetsRef.current) {
                            next[w.id] =
                                merged[w.id] ??
                                (wanted.includes(w.id) ? {} : current[w.id]);
                        }

                        return next;
                    });
                    wanted.forEach((id) => {
                        fetched.current[id] = sent[id];
                    });
                    setOfflineSince(null);
                    setUpdatedAt(Date.now());
                })
                .catch((error: unknown) => {
                    if (
                        error instanceof HttpError &&
                        GONE.includes(error.status) &&
                        onGone.current
                    ) {
                        onGone.current();

                        return;
                    }

                    setOfflineSince((since) => since ?? Date.now());
                });
        };
    }, [url, schema]);

    // First load, and a widget whose data settings changed (or that is new) refetches alone.
    useEffect(() => {
        const changed = widgetsRef.current
            .filter((w) => fetched.current[w.id] !== dataKey(schema, w))
            .map((w) => w.id);

        if (changed.length === 0) {
            return;
        }

        const first = Object.keys(fetched.current).length === 0;
        run.current(first ? undefined : changed);
    }, [keys, schema]);

    // Polling, and retrying while offline.
    useEffect(() => {
        let ticks = 0;
        const timer = window.setInterval(() => {
            ticks++;

            if (offlineSince !== null) {
                if (ticks % (RETRY_SECONDS / 10) === 0) {
                    run.current();
                }

                return;
            }

            if (ticks % (POLL_SECONDS / 10) !== 0) {
                return;
            }

            const minute = ticks / (POLL_SECONDS / 10);
            const due = widgetsRef.current
                .filter(
                    (w) =>
                        option<string>(schema, w, 'refresh') !== '5m' ||
                        minute % 5 === 0,
                )
                .map((w) => w.id);

            if (due.length > 0) {
                run.current(due);
            }
        }, 10_000);

        return () => window.clearInterval(timer);
    }, [offlineSince, schema]);

    // Live: the projects the screen shows broadcast when something changes.
    const watched =
        options.liveProjects === false
            ? ''
            : [
                  ...new Set(
                      dataWidgets
                          .filter(
                              (w) =>
                                  option<string>(schema, w, 'refresh') ===
                                  'live',
                          )
                          .flatMap((w) =>
                              projectsOf(w, screen).flatMap((own) =>
                                  own.length > 0 ? own : teamProjects,
                              ),
                          ),
                  ),
              ]
                  .sort()
                  .join(',');

    useEffect(() => {
        if (watched === '') {
            return;
        }

        let last = 0;
        let timer: number | null = null;
        const changedProjects = new Set<string>();

        const flush = () => {
            timer = null;
            last = Date.now();
            const ids = widgetsRef.current
                .filter((w) => {
                    if (option<string>(schema, w, 'refresh') !== 'live') {
                        return false;
                    }

                    return projectsOf(w, screen).some(
                        (own) =>
                            own.length === 0 ||
                            own.some((p) => changedProjects.has(p)),
                    );
                })
                .map((w) => w.id);
            changedProjects.clear();

            if (ids.length > 0) {
                run.current(ids);
            }
        };

        const subscriptions = watched.split(',').map((uuid) => {
            const channel = echo().private(`project.${uuid}`);
            const listener = () => {
                changedProjects.add(uuid);

                if (timer === null) {
                    timer = window.setTimeout(
                        flush,
                        Math.max(
                            0,
                            last + LIVE_MIN_SECONDS * 1000 - Date.now(),
                        ),
                    );
                }
            };
            PROJECT_EVENTS.forEach((event) => channel.listen(event, listener));

            return () =>
                PROJECT_EVENTS.forEach((event) =>
                    channel.stopListening(event, listener),
                );
        });

        return () => {
            subscriptions.forEach((stop) => stop());

            if (timer !== null) {
                window.clearTimeout(timer);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [watched, schema]);

    return { data, offlineSince, updatedAt };
}

/**
 * The projects each value of a widget shows (a rotated value may pick its own), an
 * empty list meaning every project of the team.
 */
function projectsOf(widget: Widget, screen: Pick<Screen, 'scope'>): string[][] {
    const own = widget.scope.projects ?? screen.scope.projects;

    return [
        own,
        ...rotationOf(widget)
            .slice(1)
            .map((item) => item.scope?.projects ?? own),
    ];
}
