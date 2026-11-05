import { useForm } from '@inertiajs/react';
import { Fragment } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import { update } from '@/routes/notifications';

type Via = 'mail' | 'toast' | 'push';

/** Each kind lists only the channels it can use; a missing one is not available. */
export type NotificationPreferences = Record<
    string,
    Partial<Record<Via, boolean>>
>;

export type PresetName = 'quiet' | 'balanced' | 'everything';

export type NotificationPresets = Record<PresetName, NotificationPreferences>;

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

/** The preset the preferences are exactly, if any. */
export function matchingPreset(
    presets: NotificationPresets,
    value: NotificationPreferences,
): PresetName | null {
    return (
        (Object.keys(presets) as PresetName[]).find((name) =>
            Object.entries(presets[name]).every(([kind, vias]) =>
                Object.entries(vias).every(
                    ([via, on]) => value[kind]?.[via as Via] === on,
                ),
            ),
        ) ?? null
    );
}

/**
 * Quiet, Balanced or Everything: fills the matrix, which can then be changed cell by cell.
 */
export function NotificationPresetPicker({
    presets,
    value,
    onChange,
}: {
    presets: NotificationPresets;
    value: NotificationPreferences;
    onChange: (value: NotificationPreferences) => void;
}) {
    const t = useT();
    const current = matchingPreset(presets, value);

    return (
        <div
            role="group"
            aria-label={t('settings.notifications.presets.label')}
            className="grid gap-2 sm:grid-cols-3"
        >
            {(Object.keys(presets) as PresetName[]).map((name) => (
                <button
                    key={name}
                    type="button"
                    aria-pressed={current === name}
                    onClick={() => onChange(presets[name])}
                    className={cn(
                        'flex cursor-pointer flex-col gap-0.5 rounded-md border border-line-strong bg-panel px-3 py-2 text-left hover:bg-panel-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber',
                        current === name &&
                            'border-amber bg-amber/10 hover:bg-amber/10',
                    )}
                >
                    <span className="text-[13px] font-medium text-ink">
                        {t(`settings.notifications.presets.${name}.label`)}
                    </span>
                    <span className="text-xs text-ink-2">
                        {t(
                            `settings.notifications.presets.${name}.description`,
                        )}
                    </span>
                </button>
            ))}
        </div>
    );
}

/**
 * Each kind of notification by channel, a checkbox per cell that kind can use.
 */
export function NotificationMatrix({
    value,
    onChange,
}: {
    value: NotificationPreferences;
    onChange: (value: NotificationPreferences) => void;
}) {
    const t = useT();

    const set = (kind: string, via: Via, on: boolean) =>
        onChange({ ...value, [kind]: { ...value[kind], [via]: on } });

    return (
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
                                    <td key={via} className="p-0">
                                        {value[kind]?.[via] === undefined ? (
                                            <span
                                                className="text-muted-foreground"
                                                title={t(
                                                    'settings.notifications.not_available',
                                                )}
                                            >
                                                <span aria-hidden>-</span>
                                                <span className="sr-only">
                                                    {t(
                                                        'settings.notifications.not_available',
                                                    )}
                                                </span>
                                            </span>
                                        ) : (
                                            // The whole cell is the click target, not just the box.
                                            <label className="flex min-h-11 w-full cursor-pointer items-center py-2">
                                                <Checkbox
                                                    checked={value[kind][via]}
                                                    onCheckedChange={(on) =>
                                                        set(
                                                            kind,
                                                            via,
                                                            on === true,
                                                        )
                                                    }
                                                    aria-label={`${t(`settings.notifications.${kind}.label`)}: ${t(`settings.notifications.${via}`)}`}
                                                />
                                            </label>
                                        )}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </Fragment>
                ))}
            </tbody>
        </table>
    );
}

/**
 * How someone hears about what concerns them (issues they watch, mentions, assignments)
 * and about their projects: by mail, by a toast while Lumberjack is open, or by a
 * browser notification.
 */
export function NotificationSettings({
    preferences,
    presets,
}: {
    preferences: NotificationPreferences;
    presets: NotificationPresets;
}) {
    const t = useT();
    const form = useForm<NotificationPreferences>(preferences);

    return (
        <div className="space-y-6">
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(update().url, { preserveScroll: true });
                }}
            >
                <NotificationPresetPicker
                    presets={presets}
                    value={form.data}
                    onChange={form.setData}
                />
                <NotificationMatrix value={form.data} onChange={form.setData} />
                <Button disabled={form.processing}>
                    {t('settings.notifications.save')}
                </Button>
            </form>
        </div>
    );
}
