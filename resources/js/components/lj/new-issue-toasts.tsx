import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { toast } from 'sonner';
import { useT } from '@/lib/i18n';
import { playChime, useSoundSetting } from '@/lib/use-sound-setting';
import { show as issueShow } from '@/routes/issues';
import type { TeamShared } from '@/types/lumberjack';

type Surfaced = {
    project: string;
    project_name: string;
    issue: string;
    title: string;
    message: string;
    kind: 'new' | 'regression' | 'escalation';
    regression: boolean;
};

type Mentioned = {
    project: string;
    project_name: string;
    issue: string;
    title: string;
    author: string;
    excerpt: string;
};

type AssignmentChanged = {
    project_name: string;
    headline: string;
    count: number;
    url: string;
};

function MentionListener({ userUuid }: { userUuid: string }) {
    const t = useT();
    const [sound] = useSoundSetting();

    useEcho<Mentioned>(`user.${userUuid}`, '.note.mentioned', (event) => {
        toast.info(
            t('common.toasts.mentioned', {
                author: event.author,
                title: event.title,
            }),
            {
                description: `${event.project_name} · ${event.excerpt}`,
                action: {
                    label: t('common.toasts.view'),
                    onClick: () =>
                        router.visit(
                            issueShow([event.project, event.issue]).url,
                        ),
                },
            },
        );

        if (sound) {
            playChime();
        }
    });

    const assignment = (event: AssignmentChanged) =>
        toast.info(event.headline, {
            description: event.project_name,
            action: {
                label: t('common.toasts.view'),
                onClick: () => router.visit(event.url),
            },
        });

    useEcho<AssignmentChanged>(
        `user.${userUuid}`,
        ['.issue.assigned', '.issue.unassigned'],
        assignment,
    );

    return null;
}

function Listener({ team }: { team: TeamShared }) {
    const t = useT();
    const [sound] = useSoundSetting();

    useEcho<Surfaced>(`team.${team.uuid}`, '.issue.surfaced', (event) => {
        const show = event.kind === 'regression' ? toast.warning : toast.error;
        const label = {
            new: t('common.toasts.new_issue'),
            regression: t('common.toasts.regression'),
            escalation: t('common.toasts.escalation'),
        }[event.kind ?? 'new'];

        show(`${label}: ${event.title}`, {
            description: `${event.project_name} · ${event.message}`,
            action: {
                label: t('common.toasts.view'),
                onClick: () =>
                    router.visit(issueShow([event.project, event.issue]).url),
            },
        });

        if (sound) {
            playChime(event.kind !== 'new');
        }
    });

    return null;
}

/**
 * A toast, and a chime unless turned off, when a new error or a regression comes in on
 * the team, when a note tags you, or when someone assigns you or takes you off issues.
 */
export function NewIssueToasts() {
    const { team, auth } = usePage<{
        team: TeamShared | null;
        auth: { user: { uuid: string } | null };
    }>().props;

    return (
        <>
            {team && <Listener key={team.uuid} team={team} />}
            {auth.user && <MentionListener userUuid={auth.user.uuid} />}
        </>
    );
}
