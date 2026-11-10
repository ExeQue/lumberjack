import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/lj/ui';
import { toast } from 'sonner';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * A piece of text, such as an issue number, that copies itself when clicked.
 */
export function CopyText({
    text,
    className,
}: {
    text: string;
    className?: string;
}) {
    const t = useT();
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            onClick={() => {
                void navigator.clipboard.writeText(text).then(() => {
                    setCopied(true);
                    toast.success(t('common.copied_text', { text }));
                    window.setTimeout(() => setCopied(false), 1500);
                });
            }}
            title={t('common.copy_text', { text })}
            aria-label={t('common.copy_text', { text })}
            className={cn(
                'group -mx-1 inline-flex items-center gap-1 rounded px-1 hover:bg-panel-2 hover:text-ink focus-visible:outline focus-visible:outline-amber',
                className,
            )}
        >
            {text}
            {copied ? (
                <Check className="size-3.5 text-ok" aria-hidden="true" />
            ) : (
                <Copy
                    className="size-3.5 opacity-60 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                    aria-hidden="true"
                />
            )}
        </button>
    );
}

/**
 * A Copy button for a token, command or file; says so in a toast.
 */
export function CopyButton({
    text,
    label,
    className,
}: {
    text: string;
    label?: string;
    className?: string;
}) {
    const t = useT();

    return (
        <Button
            className={className}
            aria-label={label}
            onClick={() => {
                void navigator.clipboard
                    .writeText(text)
                    .then(() => toast.success(t('common.copied')));
            }}
        >
            <Copy className="size-3.5" aria-hidden="true" />
            {t('common.copy')}
        </Button>
    );
}
