import { createContext, useContext, useEffect, useRef, useState } from 'react';
import type { ComponentProps, ReactNode, Ref } from 'react';
import { LogMark } from '@/components/lj/logo';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { HeaderPart, Screen } from './types';

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
 * the largest box of the screen's shape, centered, the rest left as ground. A landscape
 * screen on an upright display (a tablet or phone held up) takes all of it too: kept to
 * its shape it would be a thin band of unreadable type.
 */
export function fitBox(view: Size, ratio: number, fit: Screen['fit']): Size {
    const shape = view.w / view.h;

    if (
        fit === 'fill' ||
        Math.abs(shape / ratio - 1) <= 0.02 ||
        (shape < 1 && ratio > 1)
    ) {
        return view;
    }

    return shape > ratio
        ? { w: view.h * ratio, h: view.h }
        : { w: view.w, h: view.w / ratio };
}

/**
 * How far a kiosk's picture is turned clockwise (0, 90, 180 or 270), for a TV hung
 * sideways or upside down that cannot turn it itself: its display's setting, or ?rotate=.
 */
export const KioskRotation = createContext(0);

/** The rotation in a kiosk URL's ?rotate=, or null. */
export function rotationFromUrl(): number | null {
    const value = Number(
        new URLSearchParams(window.location.search).get('rotate'),
    );

    return [90, 180, 270].includes(value) ? value : null;
}

function useWindowSize(): Size {
    // Turned a quarter, the picture is as wide as the window is tall.
    const sideways = useContext(KioskRotation) % 180 !== 0;
    const read = () =>
        sideways
            ? { w: window.innerHeight, h: window.innerWidth }
            : { w: window.innerWidth, h: window.innerHeight };
    const [size, setSize] = useState(read);

    useEffect(() => {
        const update = () => setSize(read());
        update();
        window.addEventListener('resize', update);

        return () => window.removeEventListener('resize', update);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sideways]);

    return size;
}

/** How large each header size draws it, medium as before. */
const HEADER_ZOOM: Record<string, number> = { s: 0.8, m: 1, l: 1.3, xl: 1.65 };

/** How much of the medium margin and gap each spacing is. */
const SPACING: Record<string, number> = { none: 0, s: 0.5, m: 1, l: 1.6 };

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
    const rotation = useContext(KioskRotation);
    const turned = kiosk && !view && rotation !== 0;
    const size = view ?? windowSize;
    const box = fitBox(size, ratioOf(screen), screen.fit);
    const margin = SPACING[screen.margin] ?? 1;

    return (
        <div
            className={cn(
                'lj-screen flex items-center justify-center overflow-hidden bg-(--s-ground)',
                kiosk ? 'lj-kiosk fixed' : 'relative',
                kiosk && !turned && 'inset-0',
            )}
            style={
                view
                    ? { width: view.w, height: view.h }
                    : turned
                      ? {
                            // The window's size turned, centered and spun into place.
                            width: windowSize.w,
                            height: windowSize.h,
                            left: '50%',
                            top: '50%',
                            transform: `translate(-50%, -50%) rotate(${rotation}deg)`,
                        }
                      : undefined
            }
            data-theme={theme}
            data-idle={kiosk ? idle : undefined}
        >
            <div
                className="[container-type:size] flex flex-none flex-col gap-[1.2cqw] px-(--lj-margin-x) py-(--lj-margin-y) transition-transform duration-1000"
                style={{
                    ['--lj-margin-x' as string]: `${2.3 * margin}cqw`,
                    ['--lj-margin-y' as string]: `${1.9 * margin}cqw`,
                    width: box.w,
                    height: box.h,
                    transform: `translate(${shift[0]}px, ${shift[1]}px)`,
                }}
            >
                {screen.show_header && (
                    <header
                        className="flex items-center justify-between gap-[1cqw]"
                        // Everything in it, text, logo and gaps, at the size picked.
                        style={{ zoom: HEADER_ZOOM[screen.header.size] ?? 1 }}
                    >
                        <div className="flex min-w-0 items-center gap-[0.9cqw]">
                            {!screen.header.hide.includes('logo') && (
                                <span
                                    aria-hidden="true"
                                    className="flex size-[max(1.75rem,2.3cqw)] items-center justify-center rounded-[0.5cqw] bg-(--s-panel) [&_svg]:size-[max(1.1rem,1.5cqw)]"
                                >
                                    <LogMark />
                                </span>
                            )}
                            <div className="flex min-w-0 flex-col">
                                {!screen.header.hide.includes('name') && (
                                    <h1
                                        className={cn(
                                            'truncate leading-tight font-bold',
                                            // Alone on its line, as large as the clock.
                                            header.subtitle
                                                ? 'text-[max(1rem,1.5cqw)]'
                                                : 'text-[max(1.25rem,2.1cqw)]',
                                        )}
                                    >
                                        {header.title}
                                    </h1>
                                )}
                                {header.subtitle && (
                                    <span className="truncate text-[max(0.75rem,0.85cqw)] text-(--s-ink-2)">
                                        {header.subtitle}
                                    </span>
                                )}
                            </div>
                        </div>
                        <div className="flex items-center gap-[1.3cqw]">
                            {header.right}
                        </div>
                    </header>
                )}

                {/* Without a header the offline notice lies over the grid's top. */}
                {banner &&
                    (screen.show_header ? (
                        banner
                    ) : (
                        <div className="pointer-events-none absolute inset-x-(--lj-margin-x) top-(--lj-margin-y) z-30">
                            {banner}
                        </div>
                    ))}

                {gridProps ? (
                    <main
                        ref={gridRef}
                        {...gridProps}
                        data-gap={screen.gap}
                        className={cn(
                            'lj-grid grid min-h-0 flex-1 transition-opacity',
                            gridProps.className,
                        )}
                        style={{
                            ['--lj-cols' as string]: screen.cols,
                            ['--lj-rows' as string]: screen.rows,
                            gap: `${0.9 * (SPACING[screen.gap] ?? 1)}cqw`,
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

/** The header's title: its own, else the screen's name. */
export function kioskTitle(screen: Screen): string {
    return screen.header.title ?? screen.name;
}

/** The header's second line: team, projects, environment and period of the screen, those it shows. */
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
    const parts: [HeaderPart, string][] = [
        ['team', team],
        ['projects', names.join(', ')],
        [
            'environment',
            screen.scope.environment ?? t('screens.kiosk.all_environments'),
        ],
        ['period', t(`screens.periods.${screen.scope.period}`)],
    ];

    return parts
        .filter(([part]) => !screen.header.hide.includes(part))
        .map(([, text]) => text)
        .join(' · ');
}

/** The language the header writes in: the screen's, the TV's (browser), else the app's. */
export function kioskLocale(screen: Screen): string {
    return screen.header.locale === 'browser'
        ? navigator.language
        : (screen.header.locale ?? (document.documentElement.lang || 'en'));
}

/** A time as the screen's clock shows it, in the screen's time zone (else the browser's). */
export function kioskClock(screen: Screen, at: number): string {
    return new Intl.DateTimeFormat(kioskLocale(screen), {
        timeZone: screen.timezone ?? undefined,
        hour: '2-digit',
        minute: '2-digit',
        second: screen.header.seconds ? '2-digit' : undefined,
        hourCycle: screen.clock_format === '12' ? 'h12' : 'h23',
    }).format(new Date(at));
}

/** The formats the browser words, by Screen::DATE_FORMATS. */
const DATE_OPTIONS: Record<string, Intl.DateTimeFormatOptions> = {
    short: { weekday: 'short', day: 'numeric', month: 'short' },
    long: { weekday: 'long', day: 'numeric', month: 'long' },
    medium: { day: 'numeric', month: 'short', year: 'numeric' },
    numeric: { day: '2-digit', month: '2-digit', year: 'numeric' },
};

/**
 * A custom date's pieces, as Screen::DATE_TOKENS reads them: [text] as it is, a token,
 * other letters (no token: refused) or anything else as it is.
 */
const DATE_PIECES =
    /\[[^\]]*\]|YYYY|YY|MMMM|MMM|MM|M|dddd|ddd|DD|D|[A-Za-z]+|[^A-Za-z[]+|\[/g;
const DATE_TOKEN = /^(YYYY|YY|MMMM|MMM|MM|M|dddd|ddd|DD|D)$/;

/** A custom pattern's first piece that is no token, '' when it has no token, else null. */
export function datePatternProblem(pattern: string): string | null {
    const pieces = pattern.match(DATE_PIECES) ?? [];
    const unknown = pieces.find(
        (p) =>
            !DATE_TOKEN.test(p) && !/^\[.*\]$/.test(p) && /[A-Za-z[]/.test(p),
    );

    return unknown ?? (pieces.some((p) => DATE_TOKEN.test(p)) ? null : '');
}

/** The date by the header's clock, as the screen writes it; null without one. */
export function kioskDate(screen: Screen, at: number): string | null {
    const { date, date_format: format, date_pattern: pattern } = screen.header;

    if (!date) {
        return null;
    }

    const locale = kioskLocale(screen);
    const timeZone = screen.timezone ?? undefined;
    const part = (options: Intl.DateTimeFormatOptions, type: string) =>
        new Intl.DateTimeFormat(locale, { timeZone, ...options })
            .formatToParts(new Date(at))
            .find((p) => p.type === type)?.value ?? '';
    const tokens: Record<string, () => string> = {
        YYYY: () => part({ year: 'numeric' }, 'year'),
        YY: () => part({ year: '2-digit' }, 'year'),
        MMMM: () => part({ month: 'long' }, 'month'),
        MMM: () => part({ month: 'short' }, 'month'),
        MM: () => part({ month: '2-digit' }, 'month'),
        M: () => part({ month: 'numeric' }, 'month'),
        dddd: () => part({ weekday: 'long' }, 'weekday'),
        ddd: () => part({ weekday: 'short' }, 'weekday'),
        DD: () => part({ day: '2-digit' }, 'day'),
        D: () => part({ day: 'numeric' }, 'day'),
    };

    if (format === 'iso') {
        return `${tokens.YYYY()}-${tokens.MM()}-${tokens.DD()}`;
    }

    if (format === 'custom') {
        return pattern && datePatternProblem(pattern) === null
            ? (pattern.match(DATE_PIECES) ?? [])
                  .map((p) => tokens[p]?.() ?? p.replace(/^\[(.*)\]$/, '$1'))
                  .join('')
            : null;
    }

    return new Intl.DateTimeFormat(locale, {
        timeZone,
        ...(DATE_OPTIONS[format] ?? DATE_OPTIONS.short),
    }).format(new Date(at));
}

/**
 * Room for a screen in the designer: at a previewed size, scaled down to the width it has
 * and the window's height less the toolbar (never up), the outside taking the scaled
 * size; without one (Fit), as large as fits at the screen's shape, unscaled. Either way
 * what it draws is the TV's own layout at that size.
 */
export function PreviewScale({
    size,
    ratio,
    children,
}: {
    size: Size | null;
    ratio: number;
    children: (view: Size) => ReactNode;
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

    const tall = Math.max(200, window.innerHeight - 190);
    const fit =
        room / ratio <= tall
            ? { w: room, h: room / ratio }
            : { w: tall * ratio, h: tall };
    const view = size ?? { w: Math.floor(fit.w), h: Math.floor(fit.h) };
    const scale = size
        ? Math.min(1, (room || size.w) / size.w, tall / size.h)
        : 1;

    return (
        <div ref={ref} className="w-full">
            {room > 0 && (
                <div
                    className="relative mx-auto overflow-hidden"
                    style={{ width: view.w * scale, height: view.h * scale }}
                >
                    <div
                        className="absolute top-0 left-0 origin-top-left"
                        style={{ transform: `scale(${scale})` }}
                    >
                        {children(view)}
                    </div>
                </div>
            )}
        </div>
    );
}

/** The header's right side: attention count, live or offline since, and the clock. */
export function KioskStatus({
    attention,
    offline,
    clock,
    date = null,
    hide = [],
}: {
    /** The date by the clock, as the screen writes it; null without one. */
    date?: string | null;
    /** Parts the screen's header hides; being offline always shows. */
    hide?: HeaderPart[];
    attention: number;
    /** "Offline since 12:03", or null while live. */
    offline: string | null;
    /** Null: the screen hides its clock. */
    clock: string | null;
}) {
    const t = useT();

    return (
        <>
            {attention > 0 &&
                offline === null &&
                !hide.includes('attention') && (
                    <span className="rounded-full bg-(--s-red) px-[0.85cqw] py-[0.4cqw] text-[max(0.75rem,0.95cqw)] font-bold whitespace-nowrap text-(--s-ground)">
                        {t('screens.kiosk.attention', {}, attention)}
                    </span>
                )}
            {offline === null ? (
                !hide.includes('live') && (
                    <span className="flex items-center gap-[0.4cqw] text-[max(0.75rem,0.85cqw)] text-(--s-green)">
                        <span
                            aria-hidden="true"
                            className="size-[0.55cqw] rounded-full bg-(--s-green)"
                        />
                        {t('screens.kiosk.live')}
                    </span>
                )
            ) : (
                <span className="flex items-center gap-[0.4cqw] text-[max(0.75rem,0.95cqw)] font-semibold text-(--s-amber)">
                    <span
                        aria-hidden="true"
                        className="size-[0.55cqw] rounded-full bg-(--s-amber)"
                    />
                    {offline}
                </span>
            )}
            {clock !== null && date !== null && (
                <span className="text-[max(0.9rem,1.3cqw)] whitespace-nowrap text-(--s-ink-2)">
                    {date}
                </span>
            )}
            {clock !== null && (
                <span className="font-mono text-[max(1.25rem,2.1cqw)] font-semibold">
                    {clock}
                </span>
            )}
        </>
    );
}
