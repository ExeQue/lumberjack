import { Head, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useEffect, useState } from 'react';
import type { ComponentProps } from 'react';
import { LogMark } from '@/components/lj/logo';
import { displayTokenKey } from '@/components/lj/screen/tv';
import type { TvUrls } from '@/components/lj/screen/tv';
import { useT } from '@/lib/i18n';
import { reloadSoon } from '@/components/lj/screen/frame';
import Kiosk from '@/pages/screens/kiosk';

type KioskProps = Omit<
    ComponentProps<typeof Kiosk>,
    'share' | 'onGone' | 'reloadOnly' | 'onAlert'
>;

type Props = {
    slot: number;
    display: { uuid: string; name: string };
    display_channel: string;
    /** What the display shows; the heartbeat says when it changed. */
    version: string;
    /** The screens it shows, in turn when more than one. */
    screens: KioskProps[];
    rotate_seconds: number;
    /** Stay on a screen while one of its widgets is red. */
    hold_on_alert: boolean;
    urls: TvUrls;
};

const HEARTBEAT_MS = 60_000;

/**
 * /tv on a paired window: its display's screens in the kiosk, read only, in turn and
 * fading between them when there are several. Another screen or theme picked under
 * Displays reloads it in place; removing the display sends it back to the pairing code.
 * A heartbeat every minute catches what a missed broadcast said.
 */
export default function TvShow({
    slot,
    display,
    display_channel,
    version,
    screens,
    rotate_seconds,
    hold_on_alert,
    urls,
}: Props) {
    const t = useT();
    const [current, setCurrent] = useState(0);
    const [red, setRed] = useState<Record<string, boolean>>({});
    const shown = screens[current % Math.max(1, screens.length)];

    // Back to /tv from scratch: the display was removed, so it shows a pairing code.
    const unpair = useCallback(() => {
        try {
            localStorage.removeItem(displayTokenKey(slot));
        } catch {
            // Nothing kept, nothing to forget.
        }

        window.location.reload();
    }, [slot]);

    useEcho(display_channel, '.display.changed', () =>
        router.reload({ viewTransition: true }),
    );
    useEcho(display_channel, '.display.removed', () => unpair());
    useEcho(display_channel, '.display.reload', () => reloadSoon());

    useEffect(() => {
        // The window's size goes along, so the designer can preview a screen at it.
        const beat = () =>
            fetch(
                `${urls.heartbeat}?${new URLSearchParams({
                    w: String(window.innerWidth),
                    h: String(window.innerHeight),
                    dpr: String(window.devicePixelRatio || 1),
                })}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            )
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
        // Its size right away, and again whenever the window changes.
        const resized = () => {
            window.clearTimeout(settle);
            settle = window.setTimeout(beat, 2_000);
        };
        let settle = window.setTimeout(beat, 2_000);
        window.addEventListener('resize', resized);

        return () => {
            window.clearInterval(timer);
            window.clearTimeout(settle);
            window.removeEventListener('resize', resized);
        };
    }, [version, urls.heartbeat, unpair]);

    // In turn: the next screen every so many seconds, unless this one is red and holds.
    const holding =
        hold_on_alert && shown !== undefined && red[shown.screen.uuid];

    useEffect(() => {
        if (screens.length < 2 || holding) {
            return;
        }

        const timer = window.setTimeout(
            () => setCurrent((i) => (i + 1) % screens.length),
            rotate_seconds * 1000,
        );

        return () => window.clearTimeout(timer);
    }, [current, screens.length, rotate_seconds, holding]);

    if (shown === undefined) {
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

    return (
        <>
            {screens.map((kiosk) => {
                const visible = kiosk.screen.uuid === shown.screen.uuid;

                return (
                    <div
                        key={kiosk.screen.uuid}
                        aria-hidden={!visible || undefined}
                        inert={!visible}
                        className="transition-opacity duration-700"
                        style={{ opacity: visible ? 1 : 0 }}
                    >
                        <Kiosk
                            {...kiosk}
                            onGone={() => router.reload()}
                            reloadOnly={['screens']}
                            onAlert={(isRed) =>
                                setRed((all) =>
                                    all[kiosk.screen.uuid] === isRed
                                        ? all
                                        : {
                                              ...all,
                                              [kiosk.screen.uuid]: isRed,
                                          },
                                )
                            }
                        />
                    </div>
                );
            })}
            <Head title={shown.screen.name} />
        </>
    );
}
