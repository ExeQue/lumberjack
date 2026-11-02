import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';

/**
 * Two-step button: the first click asks in place, the second does it. Replaces window.confirm.
 */
export function ConfirmButton({
    children,
    question,
    confirmLabel,
    onConfirm,
    variant = 'danger',
    disabled,
}: {
    children: ReactNode;
    question: string;
    confirmLabel: string;
    onConfirm: () => void;
    variant?: 'default' | 'danger';
    disabled?: boolean;
}) {
    const t = useT();
    const [asking, setAsking] = useState(false);

    if (!asking) {
        return (
            <Button
                variant={variant}
                disabled={disabled}
                onClick={() => setAsking(true)}
            >
                {children}
            </Button>
        );
    }

    return (
        <span
            role="group"
            aria-label={question}
            className="inline-flex flex-wrap items-center justify-end gap-2"
        >
            <span className="text-xs text-ink-2">{question}</span>
            <Button
                variant="danger"
                autoFocus
                onClick={() => {
                    setAsking(false);
                    onConfirm();
                }}
            >
                {confirmLabel}
            </Button>
            <Button variant="ghost" onClick={() => setAsking(false)}>
                {t('common.cancel')}
            </Button>
        </span>
    );
}
