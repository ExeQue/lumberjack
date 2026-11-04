import { useForm } from '@inertiajs/react';
import { Fragment } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useT } from '@/lib/i18n';
import { update } from '@/routes/notifications';

type Via = 'mail' | 'toast' | 'push';

/** Each kind lists only the channels it can use; a missing one is not available. */
export type NotificationPreferences = Record<
    string,
    Partial<Record<Via, boolean>>
>;

const VIAS: Via[] = ['mail', 'toast', 'push'];

const GROUPS = [
    { key: 'personal', kinds: ['watching', 'mentions', 'assignments'] },
    {
        key: 'project',
        kinds: [
            'new_issues',
            'health',
            'queue_backlogs',
            'scheduled_tasks',
            'agents',
            'releases',
        ],
    },
] as const;

/**
 * How someone hears about what concerns them (issues they watch, mentions, assignments)
 * and about their projects: by mail, by a toast while Lumberjack is open, or by a
 * browser notification.
 */
export function NotificationSettings({
    preferences,
}: {
    preferences: NotificationPreferences;
}) {
    const t = useT();
    const form = useForm<NotificationPreferences>(preferences);

    const set = (kind: string, via: Via, on: boolean) =>
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
                            {VIAS.map((via) => (
                                <th key={via} className="w-20 py-1 font-medium">
                                    {t(`settings.notifications.${via}`)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {GROUPS.map((group) => (
                            <Fragment key={group.key}>
                                <tr>
                                    <th
                                        scope="colgroup"
                                        colSpan={VIAS.length + 1}
                                        className="pt-4 pb-1 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                    >
                                        {t(
                                            `settings.notifications.groups.${group.key}`,
                                        )}
                                    </th>
                                </tr>
                                {group.kinds.map((kind) => (
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
                                        {VIAS.map((via) => (
                                            <td key={via} className="py-2">
                                                {form.data[kind]?.[via] ===
                                                undefined ? (
                                                    <span
                                                        className="text-muted-foreground"
                                                        title={t(
                                                            'settings.notifications.not_available',
                                                        )}
                                                    >
                                                        <span aria-hidden>
                                                            -
                                                        </span>
                                                        <span className="sr-only">
                                                            {t(
                                                                'settings.notifications.not_available',
                                                            )}
                                                        </span>
                                                    </span>
                                                ) : (
                                                    <Checkbox
                                                        checked={
                                                            form.data[kind][via]
                                                        }
                                                        onCheckedChange={(on) =>
                                                            set(
                                                                kind,
                                                                via,
                                                                on === true,
                                                            )
                                                        }
                                                        aria-label={`${t(`settings.notifications.${kind}.label`)}: ${t(`settings.notifications.${via}`)}`}
                                                    />
                                                )}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </Fragment>
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
