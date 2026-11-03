import { usePage } from '@inertiajs/react';
import { useTeamLive } from '@/lib/use-live';
import { useProjectLive } from '@/lib/use-project-live';

/**
 * Keeps what the sidebar shows live on every page: the team's projects, the
 * viewer's role in it, and the sidebar project's badge counts.
 */
export function LiveSidebar() {
    const { team, sidebar_project } = usePage<{
        team: { uuid: string } | null;
        sidebar_project: { uuid: string } | null;
    }>().props;

    return (
        <>
            {team && <LiveTeam />}
            {sidebar_project && <LiveCounts uuid={sidebar_project.uuid} />}
        </>
    );
}

function LiveTeam() {
    useTeamLive(['projects.changed', 'members.changed'], ['team', 'teams']);

    return null;
}

function LiveCounts({ uuid }: { uuid: string }) {
    useProjectLive(
        uuid,
        [
            'issues.changed',
            'issue.updated',
            'jobs.changed',
            'schedule.changed',
            'health.changed',
            'commands.changed',
        ],
        ['sidebar_project'],
    );

    return null;
}
