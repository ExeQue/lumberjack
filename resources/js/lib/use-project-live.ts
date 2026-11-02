import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';

export type LiveKind =
    | 'issues.changed'
    | 'jobs.changed'
    | 'schedule.changed'
    | 'commands.changed'
    | 'logs.changed'
    | 'performance.changed'
    | 'agents.changed'
    | 'issue.updated';

/**
 * Reloads the given props when the project's private channel says something changed.
 * The events carry no data; the page asks the server again.
 */
export function useProjectLive(
    projectId: number,
    kinds: LiveKind[],
    only: string[],
    options: { paused?: boolean; issue?: string } = {},
): void {
    useEcho<{ issue: string | null }>(
        `project.${projectId}`,
        kinds.map((kind) => `.${kind}`),
        (payload) => {
            if (options.paused) {
                return;
            }

            if (
                options.issue !== undefined &&
                payload?.issue &&
                payload.issue !== options.issue
            ) {
                return;
            }

            router.reload({ only, preserveUrl: true } as Parameters<
                typeof router.reload
            >[0]);
        },
        [projectId, options.paused, options.issue, only.join(',')],
    );
}
