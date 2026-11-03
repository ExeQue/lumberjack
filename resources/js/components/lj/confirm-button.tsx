import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';

/**
 * A button that asks first, in a small dialog, so the question fits wherever the
 * button sits (a table cell, a card). Replaces window.confirm.
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

    return (
        <>
            <Button
                variant={variant}
                disabled={disabled}
                onClick={() => setAsking(true)}
            >
                {children}
            </Button>
            <Dialog open={asking} onOpenChange={setAsking}>
                <DialogContent className="gap-5 border-line bg-panel sm:max-w-md [&>button:last-child]:hidden">
                    <DialogTitle className="text-sm leading-relaxed font-normal text-ink">
                        {question}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {confirmLabel}
                    </DialogDescription>
                    <DialogFooter>
                        <Button onClick={() => setAsking(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button
                            variant={
                                variant === 'danger' ? 'danger' : 'primary'
                            }
                            autoFocus
                            onClick={() => {
                                setAsking(false);
                                onConfirm();
                            }}
                        >
                            {confirmLabel}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
