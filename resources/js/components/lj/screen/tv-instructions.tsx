import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Input, Mono } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { show as pairShow } from '@/routes/pair';
import { bareUrl, QrCode, withSlot } from './qr';

export type TvInstructionsProps = {
    /** /tv, to open on the TV. */
    url: string;
    /** /pair, where its code is confirmed. */
    pair_url: string;
    /** The /tv address as a QR code. */
    qr: string;
};

/**
 * How to show a screen on a TV: open /tv there, then confirm its code here (typed in,
 * or by scanning the TV with a phone). Used by the designer and by Displays.
 */
export function TvInstructions({ url, pair_url, qr }: TvInstructionsProps) {
    const t = useT();
    const [code, setCode] = useState('');
    const normalized = code.replace(/[^A-Za-z0-9]/g, '').toUpperCase();

    return (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
            <div className="flex flex-1 flex-col gap-3 text-sm">
                <ol className="flex list-decimal flex-col gap-2 pl-5 text-ink-2">
                    <li>
                        {withSlot(
                            (r) => t('screens.open_tv.step_open', r),
                            'url',
                            <Mono className="text-amber">{bareUrl(url)}</Mono>,
                        )}
                    </li>
                    <li>
                        {withSlot(
                            (r) => t('screens.open_tv.step_scan', r),
                            'url',
                            <Mono className="text-amber">
                                {bareUrl(pair_url)}
                            </Mono>,
                        )}
                    </li>
                    <li>{t('screens.open_tv.step_pick')}</li>
                </ol>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();

                        if (normalized.length === 6) {
                            router.visit(pairShow(normalized).url);
                        }
                    }}
                >
                    <label className="flex flex-col gap-1 text-xs text-ink-2">
                        {t('screens.open_tv.code')}
                        <Input
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            maxLength={7}
                            autoComplete="off"
                            autoCapitalize="characters"
                            spellCheck={false}
                            placeholder="K7Q-M2X"
                            className="w-32 font-mono tracking-widest uppercase"
                        />
                    </label>
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={normalized.length !== 6}
                    >
                        {t('screens.open_tv.continue')}
                    </Button>
                </form>
            </div>
            <div className="flex flex-col items-center gap-2">
                <QrCode
                    svg={qr}
                    label={t('screens.tv.qr_label', { url: bareUrl(url) })}
                    className="size-36"
                />
                <span className="max-w-48 text-center text-xs text-ink-3">
                    {t('screens.open_tv.qr_hint', { url: bareUrl(url) })}
                </span>
            </div>
        </div>
    );
}
