import { useEcho } from '@laravel/echo-react';
import { DEFAULT_INTERVAL_MS, useLiveReload } from '@/lib/use-live';
import type { LiveOptions, LiveReload } from '@/lib/use-live';

export type LiveKind =
    | 'issues.changed'
    | 'jobs.changed'
    | 'schedule.changed'
    | 'commands.changed'
    | 'logs.changed'
    | 'activity.changed'
    | 'performance.changed'
    | 'agents.changed'
    | 'health.changed'
    | 'settings.changed'
    | 'issue.updated';

/**
 * Milliseconds between two reloads caused by one kind. Performance changes with every
 * request a busy project serves, and its statistics are the heaviest to compute.
 */
const MIN_INTERVAL_MS: Partial<Record<LiveKind, number>> = {
    'performance.changed': 10_000,
};

/**
 * Reloads the given props when the project's private channel says something changed.
 * The events carry no data; the page asks the server again. Reloads coalesce as
 * `useLiveReload` describes.
 *
 * With `issue` set, only events about that issue count: the project-wide `issues.changed`
 * (which names no issue) is for list pages.
 */
export function useProjectLive(
    projectUuid: string,
    kinds: LiveKind[],
    only: string[],
    options: LiveOptions & { issue?: string } = {},
): LiveReload {
    const live = useLiveReload(only, options);

    useEcho<{ issue: string | null; kind?: LiveKind }>(
        `project.${projectUuid}`,
        kinds.map((kind) => `.${kind}`),
        (payload) => {
            if (
                options.issue !== undefined &&
                payload?.issue !== options.issue
            ) {
                return;
            }

            const kind: LiveKind | undefined = payload?.issue
                ? 'issue.updated'
                : payload?.kind;

            live.schedule(
                (kind && MIN_INTERVAL_MS[kind]) ?? DEFAULT_INTERVAL_MS,
            );
        },
        [projectUuid, options.issue, kinds.join(',')],
    );

    return live;
}
