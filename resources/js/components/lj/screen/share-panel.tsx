import { router } from '@inertiajs/react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { CopyButton } from '@/components/lj/copy-text';
import { Button, buttonClass, Input, Label } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { share } from '@/routes/screens';
import { qr as qrRoute, rotate } from '@/routes/screens/share';
import { QrCode } from './qr';

export type Sharing = {
    url: string | null;
    include_titles: boolean;
    qr: string | null;
    can_manage: boolean;
};

/**
 * The designer's share link: on and off, titles or only counts, copy, a new link, and
 * the QR code (on screen, and dark on white for printing). Team admins change it.
 */
export function SharePanel({
    screen,
    sharing,
}: {
    screen: string;
    sharing: Sharing;
}) {
    const t = useT();
    const shared = sharing.url !== null;
    const save = (enabled: boolean, includeTitles: boolean) =>
        router.put(
            share(screen).url,
            { enabled, include_titles: includeTitles },
            { preserveScroll: true, only: ['sharing', 'flash'] },
        );

    return (
        <div className="flex flex-col gap-4 text-sm">
            <p className="text-ink-2">{t('screens.share.hint')}</p>
            {!sharing.can_manage && (
                <p className="text-xs text-ink-3">
                    {t('screens.share.admins_only')}
                </p>
            )}
            {!shared && sharing.can_manage && (
                <div>
                    <Button variant="primary" onClick={() => save(true, false)}>
                        {t('screens.share.enable')}
                    </Button>
                </div>
            )}
            {sharing.url !== null && (
                <>
                    <div className="flex flex-wrap gap-2">
                        <Input
                            aria-label={t('screens.share.url')}
                            readOnly
                            className="min-w-0 flex-1 font-mono"
                            value={sharing.url}
                            onFocus={(e) => e.currentTarget.select()}
                        />
                        <CopyButton text={sharing.url} />
                        {sharing.can_manage && (
                            <ConfirmButton
                                variant="default"
                                question={t('screens.share.rotate_question')}
                                confirmLabel={t('screens.share.rotate')}
                                onConfirm={() =>
                                    router.post(
                                        rotate(screen).url,
                                        {},
                                        {
                                            preserveScroll: true,
                                            only: ['sharing', 'flash'],
                                        },
                                    )
                                }
                            >
                                {t('screens.share.rotate')}
                            </ConfirmButton>
                        )}
                        {sharing.can_manage && (
                            <ConfirmButton
                                question={t('screens.share.revoke_question')}
                                confirmLabel={t('screens.share.revoke')}
                                onConfirm={() => save(false, false)}
                            >
                                {t('screens.share.revoke')}
                            </ConfirmButton>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <input
                            id="screen-share-titles"
                            type="checkbox"
                            className="size-4 accent-amber"
                            checked={sharing.include_titles}
                            disabled={!sharing.can_manage}
                            onChange={(e) => save(true, e.target.checked)}
                        />
                        <Label htmlFor="screen-share-titles">
                            {t('screens.share.titles')}
                        </Label>
                    </div>
                    {sharing.qr !== null && (
                        <div className="flex flex-wrap items-end gap-3">
                            <QrCode
                                svg={sharing.qr}
                                label={t('screens.share.qr_label')}
                                className="size-36"
                            />
                            <a
                                href={qrRoute(screen).url}
                                download
                                className={buttonClass('default')}
                            >
                                {t('screens.share.download')}
                            </a>
                        </div>
                    )}
                </>
            )}
            {!shared && (
                <p className="text-xs text-ink-3">{t('screens.share.off')}</p>
            )}
        </div>
    );
}
