import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button, Input } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';

type Flash = {
    status: string | null;
    secret: { label: string; value: string } | null;
};

/**
 * Status messages become toasts; a secret (token shown once) gets a dialog it cannot be missed in.
 */
export function FlashMessages() {
    const t = useT();
    const { flash, errors } = usePage<{
        flash: Flash;
        errors: Record<string, string>;
    }>().props;
    const [secret, setSecret] = useState<Flash['secret']>(null);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (flash?.status) {
            // Fortify reports with keys ("two-factor-authentication-enabled"); say them in words.
            const key = `common.statuses.${flash.status}`;
            const said = t(key);
            toast.success(said === key ? flash.status : said);
        }

        if (flash?.secret) {
            setSecret(flash.secret);
            setCopied(false);
        }
    }, [flash]);

    useEffect(() => {
        const first = Object.values(errors ?? {})[0];

        if (
            first &&
            Object.keys(errors).some((key) =>
                [
                    'repository',
                    'channel',
                    'member',
                    'role',
                    'token',
                    'user_id',
                ].includes(key),
            )
        ) {
            toast.error(first);
        }
    }, [errors]);

    return (
        <Dialog
            open={secret !== null}
            onOpenChange={(open) => !open && setSecret(null)}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{secret?.label}</DialogTitle>
                    <DialogDescription>
                        {t('common.secret_once')}
                    </DialogDescription>
                </DialogHeader>
                <label className="sr-only" htmlFor="flash-secret">
                    {secret?.label}
                </label>
                <Input
                    id="flash-secret"
                    readOnly
                    value={secret?.value ?? ''}
                    className="w-full font-mono"
                    onFocus={(e) => e.currentTarget.select()}
                />
                <DialogFooter>
                    <Button
                        onClick={() => {
                            void navigator.clipboard.writeText(
                                secret?.value ?? '',
                            );
                            setCopied(true);
                        }}
                    >
                        {copied ? t('common.copied') : t('common.copy')}
                    </Button>
                    <Button variant="primary" onClick={() => setSecret(null)}>
                        {t('common.done')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
