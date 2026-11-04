import { Head } from '@inertiajs/react';
import { BrowserNotifications } from '@/components/lj/browser-notifications';
import type { PushSettings } from '@/components/lj/browser-notifications';
import { NotificationSettings } from '@/components/lj/notification-settings';
import type { NotificationPreferences } from '@/components/lj/notification-settings';
import { useT } from '@/lib/i18n';
import { edit } from '@/routes/notifications';

/**
 * What the user hears about and how, and the browsers they get notifications in.
 */
export default function NotificationsSettings({
    notifications,
    push,
}: {
    notifications: NotificationPreferences;
    push: PushSettings;
}) {
    const t = useT();

    return (
        <>
            <Head title={t('settings.notifications.title')} />

            <h1 className="sr-only">{t('settings.notifications.title')}</h1>

            <NotificationSettings preferences={notifications} />

            <BrowserNotifications push={push} />
        </>
    );
}

NotificationsSettings.layout = {
    breadcrumbs: [
        {
            title: 'Notification settings',
            href: edit(),
        },
    ],
};
