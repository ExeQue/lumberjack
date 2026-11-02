import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Input } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { labels as issueLabels } from '@/routes/issues';

export type IssueLabel = { id: number; name: string; color: string };

export const LABEL_COLORS = [
    'gray',
    'red',
    'orange',
    'yellow',
    'green',
    'teal',
    'blue',
    'purple',
] as const;

// Tinted chip per palette color; readable on the dark and the light theme.
const tint: Record<string, string> = {
    gray: 'border-[#8a939c]/40 bg-[#8a939c]/15 text-[#a3acb5]',
    red: 'border-[#f2705f]/40 bg-[#f2705f]/15 text-[#f2705f]',
    orange: 'border-[#f0913b]/40 bg-[#f0913b]/15 text-[#f0913b]',
    yellow: 'border-[#e2b93b]/40 bg-[#e2b93b]/15 text-[#d6ad2f]',
    green: 'border-[#4cb782]/40 bg-[#4cb782]/15 text-[#4cb782]',
    teal: 'border-[#3fb5b0]/40 bg-[#3fb5b0]/15 text-[#3fb5b0]',
    blue: 'border-[#5b9bd5]/40 bg-[#5b9bd5]/15 text-[#6aa8e0]',
    purple: 'border-[#a07de0]/40 bg-[#a07de0]/15 text-[#a989e3]',
};

export function LabelChip({
    label,
    onRemove,
}: {
    label: Pick<IssueLabel, 'name' | 'color'>;
    onRemove?: () => void;
}) {
    const t = useT();

    return (
        <span
            className={cn(
                'inline-flex h-5 items-center gap-1 rounded-full border px-2 text-[11px] font-medium whitespace-nowrap',
                tint[label.color] ?? tint.gray,
            )}
        >
            {label.name}
            {onRemove && (
                <button
                    type="button"
                    onClick={onRemove}
                    className="-mr-1 rounded-full hover:opacity-70"
                    aria-label={t('issue.labels.remove', { label: label.name })}
                >
                    <X className="size-3" aria-hidden="true" />
                </button>
            )}
        </span>
    );
}

/**
 * The issue's labels, each removable, and a field to add one: an existing label
 * of the project, or a new name that becomes a label.
 */
export function LabelEditor({
    issueKey,
    labels,
    available,
    disabled,
}: {
    issueKey: [string, string];
    labels: IssueLabel[];
    available: IssueLabel[];
    disabled?: boolean;
}) {
    const t = useT();
    const [name, setName] = useState('');

    const save = (names: string[]) =>
        router.put(
            issueLabels(issueKey).url,
            { labels: names },
            { preserveScroll: true },
        );

    const add = (e: FormEvent) => {
        e.preventDefault();
        const value = name.trim();

        if (
            value === '' ||
            labels.some((l) => l.name.toLowerCase() === value.toLowerCase())
        ) {
            setName('');

            return;
        }

        save([...labels.map((l) => l.name), value]);
        setName('');
    };

    const unused = available.filter((a) => !labels.some((l) => l.id === a.id));

    return (
        <div className="flex flex-col gap-2.5 px-4 py-3.5">
            {labels.length === 0 ? (
                <p className="text-ink-3">{t('issue.labels.none')}</p>
            ) : (
                <div className="flex flex-wrap gap-1.5">
                    {labels.map((label) => (
                        <LabelChip
                            key={label.id}
                            label={label}
                            onRemove={
                                disabled
                                    ? undefined
                                    : () =>
                                          save(
                                              labels
                                                  .filter(
                                                      (l) => l.id !== label.id,
                                                  )
                                                  .map((l) => l.name),
                                          )
                            }
                        />
                    ))}
                </div>
            )}
            {!disabled && (
                <form onSubmit={add} className="flex gap-2">
                    <label className="sr-only" htmlFor="label-add">
                        {t('issue.labels.add')}
                    </label>
                    <Input
                        id="label-add"
                        list="label-options"
                        value={name}
                        maxLength={32}
                        onChange={(e) => setName(e.target.value)}
                        placeholder={t('issue.labels.add')}
                        autoComplete="off"
                        className="min-w-0 flex-1"
                    />
                    <datalist id="label-options">
                        {unused.map((label) => (
                            <option key={label.id} value={label.name} />
                        ))}
                    </datalist>
                </form>
            )}
        </div>
    );
}
