import { Head } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';
import { LogMark } from '@/components/lj/logo';
import { useNow } from '@/components/lj/relative';
import { bareUrl, QrCode, withSlot } from '@/components/lj/screen/qr';
import { displayTokenKey } from '@/components/lj/screen/tv';
import type { TvUrls } from '@/components/lj/screen/tv';
import { sendJson } from '@/lib/http';
import { useT } from '@/lib/i18n';

type Props = {
    /** Which window of this computer: /tv/{slot}. */
    slot: number;
    urls: TvUrls;
    /** K7Q-M2X */
    code: string;
    pair_url: string;
    pair_base: string;
    /** Where this window lives once paired, /tv/{slot}; also its start page. */
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
    slot,
    urls,
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
        sendJson<{ status: string; token?: string }>(urls.claim, 'POST')
            .then(({ json }) => {
                if (json?.status === 'paired' && json.token) {
                    setPaired(true);

                    try {
                        localStorage.setItem(displayTokenKey(slot), json.token);
                    } catch {
                        // The cookie is enough; the copy is only a fallback.
                    }

                    // From the landing page too: the window lives at its own address.
                    window.location.replace(tv_url);
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
            kept = localStorage.getItem(displayTokenKey(slot));
        } catch {
            kept = null;
        }

        if (kept) {
            void sendJson(urls.restore, 'POST', { token: kept }).then(
                ({ status }) => {
                    if (status === 200) {
                        window.location.replace(tv_url);
                    } else if (status === 404) {
                        localStorage.removeItem(displayTokenKey(slot));
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
            className="lj-screen fixed inset-0 flex flex-col gap-[4vmin] overflow-y-auto px-[max(1rem,5vw)] py-[max(1rem,5.5vmin)]"
            data-theme="dark"
        >
            <Head title={t('screens.tv.title')} />
            <div className="flex items-center gap-[1.4vmin]">
                <span
                    aria-hidden="true"
                    className="flex size-[max(1.75rem,4vmin)] items-center justify-center rounded-[0.9vmin] bg-(--s-panel) [&_svg]:size-[max(1.1rem,2.7vmin)]"
                >
                    <LogMark />
                </span>
                <span className="text-[max(1rem,2.8vmin)] font-bold">
                    Lumberjack
                </span>
                {slot > 1 && (
                    <span className="ml-[1vmin] rounded-full border border-(--s-line-strong) px-[1.4vmin] py-[0.4vmin] text-[max(0.8rem,2vmin)] text-(--s-ink-2)">
                        {t('screens.tv.window', { slot })}
                    </span>
                )}
            </div>
            <main className="flex flex-1 items-center justify-center gap-[10vmin] portrait:flex-col portrait:gap-[6vmin]">
                <div className="flex max-w-[72vmin] flex-col gap-[2.7vmin] portrait:max-w-full">
                    <h1 className="text-[max(1.4rem,5.2vmin)] leading-tight font-bold text-balance">
                        {t('screens.tv.title')}
                    </h1>
                    <ol className="flex list-decimal flex-col gap-[1.3vmin] pl-[1.3em] text-[max(1rem,2.6vmin)] text-(--s-ink-2)">
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
                        className="mt-[1vmin] flex gap-[1.3vmin]"
                    >
                        {code.split('').map((ch, i) =>
                            ch === '-' ? (
                                <span
                                    key={i}
                                    aria-hidden="true"
                                    className="flex w-[3.6vmin] items-center justify-center font-mono text-[max(1.5rem,7vmin)] font-bold"
                                >
                                    -
                                </span>
                            ) : (
                                <span
                                    key={i}
                                    aria-hidden="true"
                                    className="flex h-[max(2.6rem,11vmin)] w-[max(2.1rem,9vmin)] items-center justify-center rounded-[1.3vmin] border-2 border-(--s-line-strong) bg-(--s-panel) font-mono text-[max(1.5rem,7vmin)] font-bold"
                                >
                                    {ch}
                                </span>
                            ),
                        )}
                    </div>
                    <span
                        role="status"
                        className="flex items-center gap-[1vmin] text-[max(0.9rem,2.2vmin)] text-(--s-ink-2)"
                    >
                        <span
                            aria-hidden="true"
                            className="size-[max(0.5rem,1.3vmin)] flex-none animate-pulse rounded-full bg-(--s-amber)"
                        />
                        {paired
                            ? t('screens.tv.paired')
                            : `${t('screens.tv.waiting')} · ${t('screens.tv.new_code_in', { time: `${minutes}:${String(seconds).padStart(2, '0')}` })}`}
                    </span>
                </div>
                <div className="flex flex-col items-center gap-[1.6vmin]">
                    <QrCode
                        svg={qr}
                        label={t('screens.tv.qr_label', {
                            url: bareUrl(pair_url),
                        })}
                        className="size-[39vmin] portrait:size-[55vmin]"
                    />
                    <span className="font-mono text-[max(0.8rem,2vmin)] break-all text-(--s-ink-2)">
                        {bareUrl(pair_url)}
                    </span>
                </div>
            </main>
            <p className="text-[max(0.8rem,1.9vmin)] text-(--s-ink-3)">
                {withSlot(
                    (r) => t('screens.tv.start_page', r),
                    'url',
                    <span className="font-mono">{bareUrl(tv_url)}</span>,
                )}
            </p>
        </div>
    );
}
