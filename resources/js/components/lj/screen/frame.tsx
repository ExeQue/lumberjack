import { useEffect, useRef, useState } from 'react';
import type { ComponentProps, ReactNode, Ref } from 'react';
import { LogMark } from '@/components/lj/logo';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Screen } from './types';

type Size = { w: number; h: number };

/** Loads the page afresh after up to 3 s at random, so a wall of TVs does not ask at once. */
export function reloadSoon(): void {
    window.setTimeout(() => window.location.reload(), Math.random() * 3_000);
}

/** The screen's shape as it stands: width over height, portrait flipping it. */
export function ratioOf(
    screen: Pick<Screen, 'aspect' | 'orientation'>,
): number {
    const [w, h] = screen.aspect.split(':').map(Number);

    return screen.orientation === 'portrait' ? h / w : w / h;
}

/**
 * The box a screen is laid out in on a display of this size: all of it when the screen
 * fills any display, or when the display's shape is within 2% of the screen's; else
 * the largest box of the screen's shape, centered, the rest left as ground.
 */
export function fitBox(view: Size, ratio: number, fit: Screen['fit']): Size {
    const shape = view.w / view.h;

    if (fit === 'fill' || Math.abs(shape / ratio - 1) <= 0.02) {
        return view;
    }

    return shape > ratio
        ? { w: view.h * ratio, h: view.h }
        : { w: view.w, h: view.w / ratio };
}

function useWindowSize(): Size {
    const read = () => ({ w: window.innerWidth, h: window.innerHeight });
    const [size, setSize] = useState(read);

    useEffect(() => {
        const update = () => setSize(read());
        window.addEventListener('resize', update);

        return () => window.removeEventListener('resize', update);
    }, []);

    return size;
}

/**
 * A screen as the TV shows it, header and grid, laid out at a display's size: the
 * kiosk's whole window, or the designer's preview of a resolution. Everything inside
 * scales with the box (container units), so one layout serves both and the preview is
 * what the TV shows.
 */
export function KioskFrame({
    screen,
    view,
    theme,
    kiosk = false,
    idle = false,
    shift = [0, 0],
    header,
    banner,
    gridRef,
    gridProps,
    children,
}: {
    screen: Screen;
    /** The display's size; the window's when absent. */
    view?: Size;
    theme: 'dark' | 'light';
    /** The real kiosk: fixed to the window, empty cells invisible. */
    kiosk?: boolean;
    idle?: boolean;
    /** Pixels the whole screen moves against burn-in. */
    shift?: [number, number];
    /** The right of the header: attention, live or offline, the clock. */
    header: { title: string; subtitle: string; right: ReactNode };
    /** Under the header: the offline notice. */
    banner?: ReactNode;
    gridRef?: Ref<HTMLElement>;
    gridProps?: ComponentProps<'main'>;
    /** The grid's tiles, or what shows instead of the grid when it is null. */
    children: ReactNode;
}) {
    const windowSize = useWindowSize();
    const size = view ?? windowSize;
    const box = fitBox(size, ratioOf(screen), screen.fit);

    return (
        <div
            className={cn(
                'lj-screen flex items-center justify-center overflow-hidden bg-(--s-ground)',
                kiosk ? 'lj-kiosk fixed inset-0' : 'relative',
            )}
            style={view ? { width: view.w, height: view.h } : undefined}
            data-theme={theme}
            data-idle={kiosk ? idle : undefined}
        >
            <div
                className="[container-type:size] flex flex-none flex-col gap-[1.2cqw] px-[2.3cqw] py-[1.9cqw] transition-transform duration-1000"
                style={{
                    width: box.w,
                    height: box.h,
                    transform: `translate(${shift[0]}px, ${shift[1]}px)`,
                }}
            >
                <header className="flex items-center justify-between gap-[1cqw]">
                    <div className="flex min-w-0 items-center gap-[0.9cqw]">
                        <span
                            aria-hidden="true"
                            className="flex size-[2.3cqw] items-center justify-center rounded-[0.5cqw] bg-(--s-panel) [&_svg]:size-[1.5cqw]"
                        >
                            <LogMark />
                        </span>
                        <div className="flex min-w-0 flex-col">
                            <h1 className="truncate text-[1.5cqw] leading-tight font-bold">
                                {header.title}
                            </h1>
                            <span className="truncate text-[0.85cqw] text-(--s-ink-2)">
                                {header.subtitle}
                            </span>
                        </div>
                    </div>
                    <div className="flex items-center gap-[1.3cqw]">
                        {header.right}
                    </div>
                </header>

                {banner}

                {gridProps ? (
                    <main
                        ref={gridRef}
                        {...gridProps}
                        className={cn(
                            'lj-grid grid min-h-0 flex-1 gap-[0.9cqw] transition-opacity',
                            gridProps.className,
                        )}
                        style={{
                            ['--lj-cols' as string]: screen.cols,
                            ['--lj-rows' as string]: screen.rows,
                            gridTemplateColumns: `repeat(${screen.cols}, minmax(0, 1fr))`,
                            gridTemplateRows: `repeat(${screen.rows}, minmax(0, 1fr))`,
                            ...gridProps.style,
                        }}
                    >
                        {children}
                    </main>
                ) : (
                    children
                )}
            </div>
        </div>
    );
}

/** The header's second line: team, projects, environment and period of the screen. */
export function kioskSubtitle(
    screen: Screen,
    team: string,
    projects: { uuid: string; name: string }[],
    t: (key: string) => string,
): string {
    const names =
        screen.scope.projects.length > 0
            ? projects
                  .filter((p) => screen.scope.projects.includes(p.uuid))
                  .map((p) => p.name)
            : [t('screens.kiosk.all_projects')];

    return [
        team,
        names.join(', '),
        screen.scope.environment ?? t('screens.kiosk.all_environments'),
        t(`screens.periods.${screen.scope.period}`),
    ].join(' · ');
}

/** A time as the screen's clock shows it, in the screen's time zone. */
export function kioskClock(screen: Screen, at: number): string {
    return new Intl.DateTimeFormat(document.documentElement.lang || 'en', {
        timeZone: screen.timezone,
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(new Date(at));
}

/**
 * A box of the previewed size, scaled down to the width it has and the window's height
 * less the toolbar, never up; its outside takes the scaled size, so the page lays out
 * around what shows.
 */
export function PreviewScale({
    size,
    children,
}: {
    size: Size;
    children: ReactNode;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [room, setRoom] = useState(0);

    useEffect(() => {
        const el = ref.current;

        if (!el) {
            return;
        }

        const observer = new ResizeObserver(() => setRoom(el.clientWidth));
        observer.observe(el);

        return () => observer.disconnect();
    }, []);

    const scale = Math.min(
        1,
        (room || size.w) / size.w,
        Math.max(200, window.innerHeight - 190) / size.h,
    );

    return (
        <div ref={ref} className="w-full">
            <div
                className="relative mx-auto overflow-hidden"
                style={{ width: size.w * scale, height: size.h * scale }}
            >
                <div
                    className="absolute top-0 left-0 origin-top-left"
                    style={{ transform: `scale(${scale})` }}
                >
                    {children}
                </div>
            </div>
        </div>
    );
}

/** The header's right side: attention count, live or offline since, and the clock. */
export function KioskStatus({
    attention,
    offline,
    clock,
}: {
    attention: number;
    /** "Offline since 12:03", or null while live. */
    offline: string | null;
    clock: string;
}) {
    const t = useT();

    return (
        <>
            {attention > 0 && offline === null && (
                <span className="rounded-full bg-(--s-red) px-[0.85cqw] py-[0.4cqw] text-[0.95cqw] font-bold text-(--s-ground)">
                    {t('screens.kiosk.attention', {}, attention)}
                </span>
            )}
            {offline === null ? (
                <span className="flex items-center gap-[0.4cqw] text-[0.85cqw] text-(--s-green)">
                    <span
                        aria-hidden="true"
                        className="size-[0.55cqw] rounded-full bg-(--s-green)"
                    />
                    {t('screens.kiosk.live')}
                </span>
            ) : (
                <span className="flex items-center gap-[0.4cqw] text-[0.95cqw] font-semibold text-(--s-amber)">
                    <span
                        aria-hidden="true"
                        className="size-[0.55cqw] rounded-full bg-(--s-amber)"
                    />
                    {offline}
                </span>
            )}
            <span className="font-mono text-[2.1cqw] font-semibold">
                {clock}
            </span>
        </>
    );
}
