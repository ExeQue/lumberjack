import { useState } from 'react';
import { TreeSelect } from '@/components/lj/tree-select';
import { useT } from '@/lib/i18n';
import { timezones } from './lib';

/** A time zone from a tree by region, or searched. In a form, give it a name and defaultValue. */
export function TimezoneSelect({
    id,
    name,
    value,
    defaultValue = '',
    onChange,
    emptyLabel,
}: {
    id: string;
    name?: string;
    value?: string;
    defaultValue?: string;
    onChange?: (zone: string) => void;
    /** What no choice means, such as the screen's time zone; without it one must stay picked. */
    emptyLabel?: string;
}) {
    const t = useT();
    const [own, setOwn] = useState(defaultValue);
    const current = value ?? own;

    return (
        <TreeSelect
            id={id}
            name={name}
            label={t('screens.picker.timezone')}
            value={current}
            onChange={(zone) => {
                setOwn(zone ?? '');
                onChange?.(zone ?? '');
            }}
            options={timezones(current || undefined).map((zone) => {
                const parts = zone.split('/');

                return {
                    value: zone,
                    label: parts[parts.length - 1].replaceAll('_', ' '),
                    path: parts.slice(0, -1),
                };
            })}
            placeholder={emptyLabel ?? t('screens.picker.timezone')}
            searchPlaceholder={t('screens.picker.search')}
            noMatches={t('screens.picker.no_matches')}
            clearLabel={emptyLabel}
        />
    );
}
