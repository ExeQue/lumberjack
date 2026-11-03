import { Head, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';
import { LogMark } from '@/components/lj/logo';
import { useNow } from '@/components/lj/relative';
import { placements, themeAt } from '@/components/lj/screen/lib';
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
        () => router.reload({ only: ['screen'] }),
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
    const place = placements(screen.widgets, screen.cols, screen.rows);
    const shown = screen.widgets.filter((_, i) => place[i].fits);
    const attention = shown.filter(
        (w) => statuses[w.id] === 'down' || statuses[w.id] === 'degraded',
    ).length;
    const scopeNames =
        screen.scope.projects.length > 0
            ? projects
                  .filter((p) => screen.scope.projects.includes(p.uuid))
                  .map((p) => p.name)
            : [t('screens.kiosk.all_projects')];
    const clock = (at: number) =>
        new Intl.DateTimeFormat(document.documentElement.lang || 'en', {
            timeZone: screen.timezone,
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).format(new Date(at));

    return (
        <div
            className="lj-screen lj-kiosk fixed inset-0 overflow-hidden"
            data-theme={theme}
            data-idle={idle}
        >
            <Head title={screen.name} />
            <div
                className="flex h-full flex-col gap-[1.2vw] px-[2.3vw] py-[1.9vw] transition-transform duration-1000"
                style={{ transform: `translate(${dx}px, ${dy}px)` }}
            >
                <header className="flex items-center justify-between gap-[1vw]">
                    <div className="flex min-w-0 items-center gap-[0.9vw]">
                        <span
                            aria-hidden="true"
                            className="flex size-[2.3vw] items-center justify-center rounded-[0.5vw] bg-(--s-panel) [&_svg]:size-[1.5vw]"
                        >
                            <LogMark />
                        </span>
                        <div className="flex min-w-0 flex-col">
                            <h1 className="truncate text-[1.5vw] leading-tight font-bold">
                                {screen.name}
                            </h1>
                            <span className="truncate text-[0.85vw] text-(--s-ink-2)">
                                {[
                                    team.name,
                                    scopeNames.join(', '),
                                    screen.scope.environment ??
                                        t('screens.kiosk.all_environments'),
                                    t(`screens.periods.${screen.scope.period}`),
                                ].join(' · ')}
                            </span>
                        </div>
                    </div>
                    <div className="flex items-center gap-[1.3vw]">
                        {attention > 0 && offlineSince === null && (
                            <span className="rounded-full bg-(--s-red) px-[0.85vw] py-[0.4vw] text-[0.95vw] font-bold text-(--s-ground)">
                                {t('screens.kiosk.attention', {}, attention)}
                            </span>
                        )}
                        {offlineSince === null ? (
                            <span className="flex items-center gap-[0.4vw] text-[0.85vw] text-(--s-green)">
                                <span
                                    aria-hidden="true"
                                    className="size-[0.55vw] rounded-full bg-(--s-green)"
                                />
                                {t('screens.kiosk.live')}
                            </span>
                        ) : (
                            <span className="flex items-center gap-[0.4vw] text-[0.95vw] font-semibold text-(--s-amber)">
                                <span
                                    aria-hidden="true"
                                    className="size-[0.55vw] rounded-full bg-(--s-amber)"
                                />
                                {t('screens.kiosk.offline_since', {
                                    time: clock(offlineSince),
                                })}
                            </span>
                        )}
                        <span className="font-mono text-[2.1vw] font-semibold">
                            {clock(now)}
                        </span>
                    </div>
                </header>

                {offlineSince !== null && (
                    <div
                        role="status"
                        className="flex items-center justify-between rounded-[0.7vw] border-2 border-(--s-amber) bg-(--s-amber-bg) px-[1.4vw] py-[0.9vw]"
                    >
                        <div className="flex flex-col gap-[0.2vw]">
                            <span className="text-[1.25vw] font-bold text-(--s-amber-soft)">
                                {t('screens.kiosk.offline_title')}
                            </span>
                            <span className="text-[0.9vw] text-(--s-ink-2)">
                                {t('screens.kiosk.offline_body', {
                                    time: updatedAt ? clock(updatedAt) : '-',
                                })}
                            </span>
                        </div>
                    </div>
                )}

                {gone !== null ? (
                    <div
                        role="status"
                        className="m-auto flex flex-col gap-[0.6vw] text-center text-[1.6vw] text-(--s-ink-2)"
                    >
                        <span>{t(`screens.kiosk.${gone}`)}</span>
                        {gone === 'unshared' && (
                            <span className="text-[1vw]">
                                {t('screens.kiosk.unshared_body')}
                            </span>
                        )}
                    </div>
                ) : (
                    <main
                        className="lj-grid grid min-h-0 flex-1 gap-[0.9vw] transition-opacity"
                        style={{
                            ['--lj-cols' as string]: screen.cols,
                            ['--lj-rows' as string]: screen.rows,
                            gridTemplateColumns: `repeat(${screen.cols}, minmax(0, 1fr))`,
                            gridTemplateRows: `repeat(${screen.rows}, minmax(0, 1fr))`,
                            opacity: offlineSince !== null ? 0.45 : 1,
                        }}
                    >
                        {shown.map((widget) => (
                            <WidgetTile
                                key={widget.id}
                                widget={widget}
                                schema={schema}
                                sources={sources}
                                data={data[widget.id]}
                                now={now}
                                flash={flashing[widget.id]}
                                onStatus={onStatus}
                                aria-hidden={
                                    widget.type === 'empty' || undefined
                                }
                            />
                        ))}
                    </main>
                )}
            </div>
        </div>
    );
}
