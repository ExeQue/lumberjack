import { Head, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect } from 'react';
import type { ComponentProps } from 'react';
import { LogMark } from '@/components/lj/logo';
import { DISPLAY_TOKEN_KEY } from '@/components/lj/screen/tv';
import { useT } from '@/lib/i18n';
import Kiosk from '@/pages/screens/kiosk';
import { heartbeat } from '@/routes/tv';

type KioskProps = Omit<ComponentProps<typeof Kiosk>, 'share' | 'onGone'>;

type Props = {
    display: { uuid: string; name: string };
    display_channel: string;
    /** What the display shows; the heartbeat says when it changed. */
    version: string;
} & (KioskProps | { screen: null });

const HEARTBEAT_MS = 60_000;

/** Back to /tv from scratch: the display was removed, so it shows a pairing code. */
function unpair() {
    try {
        localStorage.removeItem(DISPLAY_TOKEN_KEY);
    } catch {
        // Nothing kept, nothing to forget.
    }

    window.location.reload();
}

/**
 * /tv on a paired TV: its display's screen in the kiosk, read only. Another screen or
 * theme picked under Displays reloads it in place; removing the display sends it back
 * to the pairing code. A heartbeat every minute catches what a missed broadcast said.
 */
export default function TvShow(props: Props) {
    const t = useT();
    const { display, display_channel, version } = props;

    useEcho(display_channel, '.display.changed', () => router.reload());
    useEcho(display_channel, '.display.removed', () => unpair());

    useEffect(() => {
        const beat = () =>
            fetch(heartbeat().url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then(async (response) => {
                    if (response.status === 401) {
                        unpair();

                        return;
                    }

                    const json = (await response.json()) as {
                        version: string;
                    };

                    if (json.version !== version) {
                        router.reload();
                    }
                })
                .catch(() => {
                    // Offline: the kiosk says so; try again next minute.
                });
        const timer = window.setInterval(beat, HEARTBEAT_MS);

        return () => window.clearInterval(timer);
    }, [version]);

    if (props.screen === null) {
        return (
            <div
                className="lj-screen fixed inset-0 flex flex-col items-center justify-center gap-[1vw] text-center"
                data-theme="dark"
            >
                <Head title={display.name} />
                <span
                    aria-hidden="true"
                    className="flex size-[4vw] items-center justify-center rounded-[0.8vw] bg-(--s-panel) [&_svg]:size-[2.6vw]"
                >
                    <LogMark />
                </span>
                <h1 className="text-[2.4vw] font-bold">
                    {t('screens.kiosk.no_screen', { name: display.name })}
                </h1>
                <p className="text-[1.3vw] text-(--s-ink-2)">
                    {t('screens.kiosk.no_screen_body')}
                </p>
            </div>
        );
    }

    const kiosk = props as KioskProps;

    return <Kiosk key={kiosk.screen.uuid} {...kiosk} onGone={unpair} />;
}
