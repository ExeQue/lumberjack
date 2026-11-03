import { useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useT } from '@/lib/i18n';
import { update } from '@/routes/notifications';

type Via = { mail: boolean; toast: boolean };

export type NotificationPreferences = {
    watching: Via;
    mentions: Via;
    assignments: Via;
};

const KINDS = ['watching', 'mentions', 'assignments'] as const;

/**
 * How someone hears about issues they watch, mentions and assignments: by mail, by a
 * toast while Lumberjack is open, or both.
 */
export function NotificationSettings({
    preferences,
}: {
    preferences: NotificationPreferences;
}) {
    const t = useT();
    const form = useForm<NotificationPreferences>(preferences);

    const set = (kind: (typeof KINDS)[number], via: keyof Via, on: boolean) =>
        form.setData(kind, { ...form.data[kind], [via]: on });

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('settings.notifications.title')}
                description={t('settings.notifications.description')}
            />
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(update().url, { preserveScroll: true });
                }}
            >
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-muted-foreground">
                            <th className="py-1 font-medium">
                                <span className="sr-only">
                                    {t('settings.notifications.what')}
                                </span>
                            </th>
                            <th className="w-20 py-1 font-medium">
                                {t('settings.notifications.mail')}
                            </th>
                            <th className="w-20 py-1 font-medium">
                                {t('settings.notifications.toast')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {KINDS.map((kind) => (
                            <tr key={kind} className="border-t">
                                <td className="py-2 pr-4">
                                    <div className="font-medium">
                                        {t(
                                            `settings.notifications.${kind}.label`,
                                        )}
                                    </div>
                                    <div className="text-muted-foreground">
                                        {t(
                                            `settings.notifications.${kind}.description`,
                                        )}
                                    </div>
                                </td>
                                {(['mail', 'toast'] as const).map((via) => (
                                    <td key={via} className="py-2">
                                        <Checkbox
                                            checked={form.data[kind][via]}
                                            onCheckedChange={(on) =>
                                                set(kind, via, on === true)
                                            }
                                            aria-label={`${t(`settings.notifications.${kind}.label`)}: ${t(`settings.notifications.${via}`)}`}
                                        />
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
                <Button disabled={form.processing}>
                    {t('settings.notifications.save')}
                </Button>
            </form>
        </div>
    );
}
