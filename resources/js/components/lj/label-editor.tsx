import { Pencil } from 'lucide-react';
import { useState } from 'react';
import { Button, Input } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';

/**
 * A pencil beside a recorded value (a campaign id, an activity) that gives it a readable
 * name in place; an empty name goes back to the value.
 */
export function LabelEditor({
    value,
    label,
    field,
    onSave,
}: {
    value: string;
    label: string | null | undefined;
    /** Names the field for assistive technology: what is named, and that empty forgets. */
    field: string;
    onSave: (label: string) => void;
}) {
    const t = useT();
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(label ?? '');
    const id = `label-${value}`;

    if (!editing) {
        return (
            <button
                type="button"
                onClick={() => {
                    setDraft(label ?? '');
                    setEditing(true);
                }}
                className="relative ml-2 inline-flex items-center gap-1 rounded border border-line-strong px-1.5 py-0.5 align-middle text-[11px] text-ink-2 hover:border-amber hover:text-amber"
                aria-label={field}
            >
                <Pencil aria-hidden="true" className="size-3" />
                {label ? t('common.label.rename') : t('common.label.name')}
            </button>
        );
    }

    return (
        <form
            className="relative mt-1 flex gap-1.5"
            onSubmit={(e) => {
                e.preventDefault();
                onSave(draft.trim());
                setEditing(false);
            }}
        >
            <label className="sr-only" htmlFor={id}>
                {field}
            </label>
            <Input
                id={id}
                autoFocus
                maxLength={100}
                value={draft}
                placeholder={value}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={(e) => e.key === 'Escape' && setEditing(false)}
                className="h-7 min-w-0 flex-1 text-xs"
            />
            <Button type="submit" className="h-7">
                {t('common.save')}
            </Button>
        </form>
    );
}
