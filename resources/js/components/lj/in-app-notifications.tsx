import { router } from '@inertiajs/react';
import { useEchoNotification } from '@laravel/echo-react';
import { toast } from 'sonner';
import { useT } from '@/lib/i18n';
import { playChime, useSoundSetting } from '@/lib/use-sound-setting';

/** What the server's notifications carry, the same as their browser push. */
type Payload = {
    type: string;
    kind: string;
    title: string;
    body: string;
    url: string;
    tag: string;
};

const CHIMES = ['new_issues', 'scheduled_tasks'];

/**
 * The toast for a notification sent to the user's own channel (new issues, health
 * checks, queue backlogs, scheduled tasks, agents, releases), as chosen under
 * Notifications. A newer one with the same tag replaces the older.
 */
export function InAppNotifications({ userUuid }: { userUuid: string }) {
    const t = useT();
    const [sound] = useSoundSetting();

    useEchoNotification<Payload>(`user.${userUuid}`, (event) => {
        if (event.type !== 'lumberjack.notification') {
            return;
        }

        toast.info(event.title, {
            id: event.tag,
            description: event.body,
            action: {
                label: t('common.toasts.view'),
                onClick: () => router.visit(event.url),
            },
        });

        if (sound && CHIMES.includes(event.kind)) {
            playChime();
        }
    });

    return null;
}
