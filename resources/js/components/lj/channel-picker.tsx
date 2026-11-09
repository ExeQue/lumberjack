import { useState } from 'react';
import type { Channel } from '@/components/lj/alerts';
import { Button, Switch } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';

/**
 * How many channels are picked, opening a dialog with every channel grouped by type.
 * The choice is handed over when the dialog is confirmed, so cancelling changes nothing.
 */
export function ChannelPicker({
    title,
    channels,
    selected,
    onChange,
    disabled = false,
}: {
    /** What the channels are picked for, such as the rule's name. */
    title: string;
    channels: Channel[];
    selected: number[];
    onChange: (selected: number[]) => void;
    disabled?: boolean;
}) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(selected);
    const picked = channels.filter((c) => selected.includes(c.id));
    const groups = Object.entries(
        Object.groupBy(channels, (channel) => channel.type),
    )
        .map(
            ([type, members]) =>
                [t(`alerts.types.${type}`), members ?? []] as const,
        )
        .sort(([a], [b]) => a.localeCompare(b));

    return (
        <>
            <Button
                onClick={() => {
                    setDraft(selected);
                    setOpen(true);
                }}
                className="w-fit"
                title={picked.map((c) => c.name).join(', ') || undefined}
            >
                {t('alerts.rules.channel_count', {}, picked.length)}
                <span className="sr-only"> {title}</span>
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('alerts.rules.pick_channels', { rule: title })}
                        </DialogTitle>
                        <DialogDescription>
                            {t(
                                'alerts.rules.channels_picked',
                                {},
                                draft.filter((id) =>
                                    channels.some((c) => c.id === id),
                                ).length,
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex max-h-[60vh] flex-col gap-4 overflow-y-auto pr-1">
                        {groups.map(([label, members]) => (
                            <fieldset
                                key={label}
                                className="flex flex-col gap-1.5"
                                disabled={disabled}
                            >
                                <legend className="mb-1 text-xs font-medium text-ink-3">
                                    {label}
                                </legend>
                                <div className="grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
                                    {members.map((channel) => (
                                        <label
                                            key={channel.id}
                                            className="flex min-w-0 cursor-pointer items-center gap-3 pointer-coarse:min-h-10"
                                        >
                                            <Switch
                                                checked={draft.includes(
                                                    channel.id,
                                                )}
                                                onChange={(on) =>
                                                    setDraft(
                                                        on
                                                            ? [
                                                                  ...draft,
                                                                  channel.id,
                                                              ]
                                                            : draft.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      channel.id,
                                                              ),
                                                    )
                                                }
                                                label={channel.name}
                                                small
                                                disabled={disabled}
                                            />
                                            <span className="min-w-0 break-words">
                                                {channel.name}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        ))}
                    </div>
                    <DialogFooter>
                        <Button onClick={() => setOpen(false)}>
                            {disabled ? t('common.close') : t('common.cancel')}
                        </Button>
                        {!disabled && (
                            <Button
                                variant="primary"
                                onClick={() => {
                                    onChange(draft);
                                    setOpen(false);
                                }}
                            >
                                {t('alerts.rules.use_channels')}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
