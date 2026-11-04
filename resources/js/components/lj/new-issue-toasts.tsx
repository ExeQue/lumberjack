import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { InAppNotifications } from '@/components/lj/in-app-notifications';
import { toast } from 'sonner';
import { useT } from '@/lib/i18n';
import { playChime, useSoundSetting } from '@/lib/use-sound-setting';
import { show as issueShow } from '@/routes/issues';

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

    // Something happened to an issue you watch: the same shape as an assignment.
    useEcho<AssignmentChanged>(
        `user.${userUuid}`,
        '.issue.watched',
        assignment,
    );

    return null;
}

/**
 * A toast when a note tags you, when someone assigns you or takes you off issues, or
 * when something happens to an issue you watch; and, through InAppNotifications, for
 * your projects (new issues with their chime, health checks and the rest) as chosen
 * under Notifications.
 */
export function NewIssueToasts() {
    const { auth } = usePage<{
        auth: { user: { uuid: string } | null };
    }>().props;

    return auth.user ? (
        <>
            <MentionListener userUuid={auth.user.uuid} />
            <InAppNotifications userUuid={auth.user.uuid} />
        </>
    ) : null;
}
