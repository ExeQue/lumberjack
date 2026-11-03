import { Head, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';
import { useNow } from '@/components/lj/relative';
import {
    KioskFrame,
    KioskStatus,
    kioskClock,
    kioskSubtitle,
    reloadSoon,
} from '@/components/lj/screen/frame';
import { fits, themeAt } from '@/components/lj/screen/lib';
import type {
    Schema,
    Screen,
    Sources,
    Status,
} from '@/components/lj/screen/types';
import { useScreenData } from '@/components/lj/screen/use-screen-data';
import { WidgetTile } from '@/components/lj/screen/widgets';
import { useT } from '@/lib/i18n';

type Props = {
    screen: Screen;
    team: { name: string };
    projects: { uuid: string; name: string }[];
    schema: Schema;
    sources: Sources;
    /** Where this kiosk reads data and hears about changes; a paired display gets its own. */
    data_url: string;
    channel: string;
    /** Shown by a share link: a public channel, no project channels, "unshared" when it stops. */
    share?: boolean;
    /** The data URL says the screen is gone for this viewer (a paired TV: display removed). */
    onGone?: () => void;
    /** What to reload when the designer saved; a TV showing screens in turn reloads them all. */
    reloadOnly?: string[];
    /** Whether a widget is red, for a TV that holds a screen while it is. */
    onAlert?: (red: boolean) => void;
};

/** Pixel offsets the whole screen cycles through against burn-in. */
const SHIFTS = [
    [0, 0],
    [3, 1],
    [-2, 3],
    [2, -3],
    [-3, -1],
];
const SHIFT_EVERY_MS = 3 * 60_000;
const IDLE_MS = 3_000;

/**
 * A status screen full screen, for a TV: no app around it, large type, the cursor
 * hidden when still, everything shifting a few pixels now and then. Layout changes from
 * the designer arrive live; data refreshes on its own and goes dim, with "offline
 * since", while the server cannot be reached.
 */
export default function Kiosk({
    screen,
    team,
    projects,
    schema,
    sources,
    data_url,
    channel,
    share = false,
    onGone,
    reloadOnly = ['screen'],
    onAlert,
}: Props) {
    const t = useT();
    const now = useNow();
    const [gone, setGone] = useState<'deleted' | 'unshared' | null>(null);
    const [idle, setIdle] = useState(false);
    const [statuses, setStatuses] = useState<Record<string, Status>>({});
    const [flashing, setFlashing] = useState<Record<string, boolean>>({});
    const previous = useRef<Record<string, Status>>({});
    const { data, offlineSince, updatedAt } = useScreenData(
        data_url,
        screen,
        schema,
        projects.map((p) => p.uuid),
        {
            liveProjects: !share,
            onGone: onGone ?? (share ? () => setGone('unshared') : undefined),
        },
    );

    // The designer saved: the screen's definition reloads in place, data already shown stays.
    useEcho(
        channel,
        '.screen.changed',
        // A view transition glides moved and resized widgets to their new cells.
        () => router.reload({ only: reloadOnly, viewTransition: true }),
        [],
        share ? 'public' : 'private',
    );
    // Someone asked everything showing the screen to load afresh.
    useEcho(
        channel,
        '.screen.reload',
        () => reloadSoon(),
        [],
        share ? 'public' : 'private',
    );
    useEcho(
        channel,
        ['.screen.deleted', '.screen.unshared'],
        (_payload: unknown) => setGone(share ? 'unshared' : 'deleted'),
        [],
        share ? 'public' : 'private',
    );

    // The cursor hides after a few seconds without moving.
    useEffect(() => {
        let timer = window.setTimeout(() => setIdle(true), IDLE_MS);
        const move = () => {
            setIdle(false);
            window.clearTimeout(timer);
            timer = window.setTimeout(() => setIdle(true), IDLE_MS);
        };
        window.addEventListener('pointermove', move);

        return () => {
            window.removeEventListener('pointermove', move);
            window.clearTimeout(timer);
        };
    }, []);

    const onStatus = (id: string, status: Status) => {
        const before = previous.current[id];
        previous.current[id] = status;
        setStatuses((all) =>
            all[id] === status ? all : { ...all, [id]: status },
        );

        // A tile flashes once when it crosses into red, not when it starts there.
        if (before !== undefined && before !== 'down' && status === 'down') {
            setFlashing((all) => ({ ...all, [id]: true }));
            window.setTimeout(
                () => setFlashing((all) => ({ ...all, [id]: false })),
                1800,
            );
        }
    };

    const theme = themeAt(screen, now);
    const [dx, dy] = SHIFTS[Math.floor(now / SHIFT_EVERY_MS) % SHIFTS.length];
    // A widget outside the grid (the grid got smaller) waits in the designer.
    const shown = screen.widgets.filter((w) =>
        fits(w, screen.cols, screen.rows),
    );
    const attention = shown.filter(
        (w) => statuses[w.id] === 'down' || statuses[w.id] === 'degraded',
    ).length;
    const red = shown.some((w) => statuses[w.id] === 'down');

    useEffect(() => onAlert?.(red), [red, onAlert]);
    const clock = (at: number) => kioskClock(screen, at);

    return (
        <KioskFrame
            screen={screen}
            theme={theme}
            kiosk
            idle={idle}
            shift={[dx, dy]}
            header={{
                title: screen.name,
                subtitle: kioskSubtitle(screen, team.name, projects, t),
                right: (
                    <KioskStatus
                        attention={attention}
                        offline={
                            offlineSince === null
                                ? null
                                : t('screens.kiosk.offline_since', {
                                      time: clock(offlineSince),
                                  })
                        }
                        clock={clock(now)}
                    />
                ),
            }}
            banner={
                offlineSince !== null && (
                    <div
                        role="status"
                        className="flex items-center justify-between rounded-[0.7cqw] border-2 border-(--s-amber) bg-(--s-amber-bg) px-[1.4cqw] py-[0.9cqw]"
                    >
                        <div className="flex flex-col gap-[0.2cqw]">
                            <span className="text-[1.25cqw] font-bold text-(--s-amber-soft)">
                                {t('screens.kiosk.offline_title')}
                            </span>
                            <span className="text-[0.9cqw] text-(--s-ink-2)">
                                {t('screens.kiosk.offline_body', {
                                    time: updatedAt ? clock(updatedAt) : '-',
                                })}
                            </span>
                        </div>
                    </div>
                )
            }
            gridProps={
                gone === null
                    ? { style: { opacity: offlineSince !== null ? 0.45 : 1 } }
                    : undefined
            }
        >
            <Head title={screen.name} />
            {gone !== null ? (
                <div
                    role="status"
                    className="m-auto flex flex-col gap-[0.6cqw] text-center text-[1.6cqw] text-(--s-ink-2)"
                >
                    <span>{t(`screens.kiosk.${gone}`)}</span>
                    {gone === 'unshared' && (
                        <span className="text-[1cqw]">
                            {t('screens.kiosk.unshared_body')}
                        </span>
                    )}
                </div>
            ) : (
                shown.map((widget) => (
                    <WidgetTile
                        key={widget.id}
                        widget={widget}
                        schema={schema}
                        sources={sources}
                        data={data[widget.id]}
                        now={now}
                        flash={flashing[widget.id]}
                        onStatus={onStatus}
                        style={{
                            viewTransitionName:
                                `w-${screen.uuid}-${widget.id}`.replace(
                                    /[^A-Za-z0-9_-]/g,
                                    '_',
                                ),
                        }}
                        aria-hidden={widget.type === 'empty' || undefined}
                    />
                ))
            )}
        </KioskFrame>
    );
}
