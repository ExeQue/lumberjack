import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { BrowserNotifications } from '@/components/lj/browser-notifications';
import type { PushSettings } from '@/components/lj/browser-notifications';
import { NotificationSettings } from '@/components/lj/notification-settings';
import type {
    NotificationPreferences,
    NotificationPresets,
} from '@/components/lj/notification-settings';
import { buttonClass } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { edit } from '@/routes/notifications';
import { show as onboarding } from '@/routes/onboarding';

/**
 * What the user hears about and how, and the browsers they get notifications in.
 */
export default function NotificationsSettings({
    notifications,
    presets,
    push,
}: {
    notifications: NotificationPreferences;
    presets: NotificationPresets;
    push: PushSettings;
}) {
    const t = useT();

    return (
        <>
            <Head title={t('settings.notifications.title')} />

            <h1 className="sr-only">{t('settings.notifications.title')}</h1>

            <NotificationSettings
                preferences={notifications}
                presets={presets}
            />

            <BrowserNotifications push={push} />

            <div className="space-y-4">
                <Heading
                    variant="small"
                    title={t('settings.onboarding.title')}
                    description={t('settings.onboarding.description')}
                />
                <Link href={onboarding()} className={buttonClass()}>
                    {t('settings.onboarding.open')}
                </Link>
            </div>
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
