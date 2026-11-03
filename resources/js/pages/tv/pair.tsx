import { Head } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';
import { LogMark } from '@/components/lj/logo';
import { useNow } from '@/components/lj/relative';
import { bareUrl, QrCode, withSlot } from '@/components/lj/screen/qr';
import { DISPLAY_TOKEN_KEY } from '@/components/lj/screen/tv';
import { sendJson } from '@/lib/http';
import { useT } from '@/lib/i18n';
import { pairing as pairingRoute, restore as restoreRoute } from '@/routes/tv';

type Props = {
    /** K7Q-M2X */
    code: string;
    pair_url: string;
    pair_base: string;
    tv_url: string;
    qr: string;
    /** Seconds the code has left; counted from here, so a TV's wrong clock does not matter. */
    expires_in: number;
    /** The private channel that says the code was confirmed; named without the code. */
    channel: string;
};

const POLL_MS = 3_000;

/**
 * /tv while the TV is not paired: a code and a QR code to confirm on a phone. Learns of
 * the confirmation over Reverb, or by asking every 3 seconds, then takes its display
 * token (a cookie, plus a copy in local storage) and opens its screen.
 */
export default function TvPair({
    code,
    pair_url,
    pair_base,
    tv_url,
    qr,
    expires_in,
    channel,
}: Props) {
    const t = useT();
    const now = useNow();
    const [paired, setPaired] = useState(false);
    const asking = useRef(false);
    const [deadline] = useState(() => Date.now() + expires_in * 1000);

    const ask = () => {
        if (asking.current) {
            return;
        }

        asking.current = true;
        sendJson<{ status: string; token?: string }>(pairingRoute().url, 'POST')
            .then(({ json }) => {
                if (json?.status === 'paired' && json.token) {
                    setPaired(true);

                    try {
                        localStorage.setItem(DISPLAY_TOKEN_KEY, json.token);
                    } catch {
                        // The cookie is enough; the copy is only a fallback.
                    }

                    window.location.reload();
                } else if (json?.status === 'expired') {
                    window.location.reload();
                }
            })
            .catch(() => {})
            .finally(() => {
                asking.current = false;
            });
    };

    useEcho(channel, '.pairing.confirmed', () => ask());

    // Polling, and a TV that lost its cookie but kept the token gets it back.
    useEffect(() => {
        let kept: string | null = null;

        try {
            kept = localStorage.getItem(DISPLAY_TOKEN_KEY);
        } catch {
            kept = null;
        }

        if (kept) {
            void sendJson(restoreRoute().url, 'POST', { token: kept }).then(
                ({ status }) => {
                    if (status === 200) {
                        window.location.reload();
                    } else if (status === 404) {
                        localStorage.removeItem(DISPLAY_TOKEN_KEY);
                    }
                },
            );
        }

        const timer = window.setInterval(ask, POLL_MS);

        return () => window.clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const left = Math.max(0, deadline - now);

    useEffect(() => {
        if (left === 0) {
            window.location.reload();
        }
    }, [left]);

    const minutes = Math.floor(left / 60_000);
    const seconds = Math.floor((left % 60_000) / 1000);

    return (
        <div
            className="lj-screen fixed inset-0 flex flex-col overflow-hidden px-[5vw] py-[3.3vw]"
            data-theme="dark"
        >
            <Head title={t('screens.tv.title')} />
            <div className="flex items-center gap-[0.8vw]">
                <span
                    aria-hidden="true"
                    className="flex size-[2.3vw] items-center justify-center rounded-[0.5vw] bg-(--s-panel) [&_svg]:size-[1.5vw]"
                >
                    <LogMark />
                </span>
                <span className="text-[1.6vw] font-bold">Lumberjack</span>
            </div>
            <main className="flex flex-1 flex-wrap items-center justify-center gap-[6vw]">
                <div className="flex max-w-[40vw] flex-col gap-[1.5vw]">
                    <h1 className="text-[2.9vw] leading-tight font-bold">
                        {t('screens.tv.title')}
                    </h1>
                    <ol className="flex list-decimal flex-col gap-[0.7vw] pl-[1.9vw] text-[1.45vw] text-(--s-ink-2)">
                        <li>
                            {withSlot(
                                (r) => t('screens.tv.step_scan', r),
                                'url',
                                <span className="font-mono text-(--s-amber-soft)">
                                    {bareUrl(pair_base)}
                                </span>,
                            )}
                        </li>
                        <li>{t('screens.tv.step_code')}</li>
                        <li>{t('screens.tv.step_confirm')}</li>
                    </ol>
                    <div
                        role="img"
                        aria-label={t('screens.tv.code_label', { code })}
                        className="mt-[0.6vw] flex gap-[0.75vw]"
                    >
                        {code.split('').map((ch, i) =>
                            ch === '-' ? (
                                <span
                                    key={i}
                                    aria-hidden="true"
                                    className="flex w-[2vw] items-center justify-center font-mono text-[4vw] font-bold"
                                >
                                    -
                                </span>
                            ) : (
                                <span
                                    key={i}
                                    aria-hidden="true"
                                    className="flex h-[6.2vw] w-[5vw] items-center justify-center rounded-[0.75vw] border-2 border-(--s-line-strong) bg-(--s-panel) font-mono text-[4vw] font-bold"
                                >
                                    {ch}
                                </span>
                            ),
                        )}
                    </div>
                    <span
                        role="status"
                        className="flex items-center gap-[0.6vw] text-[1.25vw] text-(--s-ink-2)"
                    >
                        <span
                            aria-hidden="true"
                            className="size-[0.75vw] animate-pulse rounded-full bg-(--s-amber)"
                        />
                        {paired
                            ? t('screens.tv.paired')
                            : `${t('screens.tv.waiting')} · ${t('screens.tv.new_code_in', { time: `${minutes}:${String(seconds).padStart(2, '0')}` })}`}
                    </span>
                </div>
                <div className="flex flex-col items-center gap-[0.9vw]">
                    <QrCode
                        svg={qr}
                        label={t('screens.tv.qr_label', {
                            url: bareUrl(pair_url),
                        })}
                        className="size-[22vw]"
                    />
                    <span className="font-mono text-[1.15vw] text-(--s-ink-2)">
                        {bareUrl(pair_url)}
                    </span>
                </div>
            </main>
            <p className="text-[1.05vw] text-(--s-ink-3)">
                {withSlot(
                    (r) => t('screens.tv.start_page', r),
                    'url',
                    <span className="font-mono">{bareUrl(tv_url)}</span>,
                )}
            </p>
        </div>
    );
}
