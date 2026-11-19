import { useId, type CSSProperties, type ReactNode } from 'react';
import { getStaticFiles, staticFile, useCurrentFrame } from 'remotion';
import {
    easeBack,
    easeInOut,
    easeOut,
    progress,
    tween,
    useTime,
    visible,
} from './anim';
import { C, FONT, MONO, tone, type Tone } from './theme';

/** The rings of the logo, drawn in as `draw` goes from 0 to 1. */
export const Logo = ({
    size = 96,
    draw = 1,
    box = true,
}: {
    size?: number;
    draw?: number;
    box?: boolean;
}) => {
    const ring = (r: number, i: number) => {
        const length = 2 * Math.PI * r;
        const p = Math.min(1, Math.max(0, draw * 1.6 - i * 0.25));

        return { strokeDasharray: length, strokeDashoffset: length * (1 - p) };
    };

    return (
        <svg width={size} height={size} viewBox="0 0 32 32">
            {box ? <rect width="32" height="32" rx="7" fill={C.panel} /> : null}
            <g
                fill="none"
                stroke={C.amber}
                strokeWidth="2"
                strokeLinecap="round"
            >
                <circle
                    cx="16"
                    cy="16"
                    r="11"
                    style={ring(11, 0)}
                    transform="rotate(-90 16 16)"
                />
                <circle
                    cx="16.6"
                    cy="16.6"
                    r="7"
                    style={ring(7, 1)}
                    transform="rotate(-90 16.6 16.6)"
                />
                <circle
                    cx="17.1"
                    cy="17.1"
                    r="3"
                    style={ring(3, 2)}
                    transform="rotate(-90 17.1 17.1)"
                />
                <path
                    d="M15 5l1.2 5.6"
                    style={{ opacity: progress(draw, 0.7, 0.3) }}
                />
            </g>
        </svg>
    );
};

/** The background behind every scene: growth rings and a warm glow. */
export const Backdrop = ({
    glowX,
    glowY,
    ringScale = 1,
}: {
    glowX: number;
    glowY: number;
    ringScale?: number;
}) => {
    const frame = useCurrentFrame();

    return (
        <div style={{ position: 'absolute', inset: 0, overflow: 'hidden' }}>
            <div
                style={{
                    position: 'absolute',
                    inset: 0,
                    background:
                        'radial-gradient(1400px 900px at 50% 40%, #171b20, #0b0d10 75%)',
                }}
            />
            <svg
                viewBox="-1000 -1000 2000 2000"
                style={{
                    position: 'absolute',
                    left: '50%',
                    top: '50%',
                    width: 2800,
                    height: 2800,
                    margin: '-1400px 0 0 -1400px',
                    transform: `rotate(${frame * 0.01}deg) scale(${ringScale})`,
                }}
            >
                {Array.from({ length: 14 }, (_, i) => (
                    <circle
                        key={i}
                        cx={i * 4}
                        cy={i * 3}
                        r={90 + i * 72 + (i % 3) * 9}
                        fill="none"
                        stroke={C.amber}
                        strokeWidth={1.1}
                        opacity={0.07 + (i % 4 === 0 ? 0.04 : 0)}
                    />
                ))}
            </svg>
            <div
                style={{
                    position: 'absolute',
                    left: glowX - 520,
                    top: glowY - 520,
                    width: 1040,
                    height: 1040,
                    borderRadius: '50%',
                    background:
                        'radial-gradient(closest-side, rgba(240,169,59,0.22), rgba(240,169,59,0.06) 55%, transparent)',
                }}
            />
            <div
                style={{
                    position: 'absolute',
                    inset: 0,
                    background:
                        'radial-gradient(ellipse at center, transparent 55%, rgba(0,0,0,0.55))',
                }}
            />
        </div>
    );
};

/** Words that blur in one by one. Words wrapped in `*` are amber. */
export const Words = ({
    text,
    at,
    stagger = 0.07,
    style,
}: {
    text: string;
    at: number;
    stagger?: number;
    style?: CSSProperties;
}) => {
    const t = useTime();

    return (
        <span style={style}>
            {text.split(' ').map((word, i) => {
                const p = progress(t, at + i * stagger, 0.8);
                const accent = word.startsWith('*');

                return (
                    <span
                        key={i}
                        style={{
                            display: 'inline-block',
                            marginRight: '0.26em',
                            opacity: p,
                            transform: `translateY(${(1 - p) * 40}px)`,
                            filter:
                                p < 0.99
                                    ? `blur(${(1 - p) * 10}px)`
                                    : undefined,
                            color: accent ? C.amber : undefined,
                        }}
                    >
                        {word.replaceAll('*', '')}
                    </span>
                );
            })}
        </span>
    );
};

export type Sub = [text: string, at: number];

/** A kicker, a title, and lines under it that take turns. */
export const Headline = ({
    kicker,
    title,
    subs = [],
    at = 0,
    out,
    width = 640,
    align = 'left',
    size = 76,
}: {
    kicker: string;
    title: string;
    subs?: Sub[];
    at?: number;
    out?: number;
    width?: number;
    align?: 'left' | 'center';
    size?: number;
}) => {
    const t = useTime();
    const k = progress(t, at, 0.6);
    const leave = out === undefined ? 0 : progress(t, out, 0.5, easeInOut);

    return (
        <div
            style={{
                width,
                textAlign: align,
                opacity: 1 - leave,
                transform: `translateY(${-leave * 30}px)`,
                filter: leave > 0.01 ? `blur(${leave * 8}px)` : undefined,
            }}
        >
            <div
                style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 14,
                    fontFamily: MONO,
                    fontSize: 22,
                    letterSpacing: 3,
                    textTransform: 'uppercase',
                    color: C.amber,
                    opacity: k,
                    transform: `translateX(${(1 - k) * -24}px)`,
                }}
            >
                <span
                    style={{
                        width: 36 * k,
                        height: 2,
                        background: C.amber,
                        display: 'inline-block',
                    }}
                />
                {kicker}
            </div>
            <h1
                style={{
                    margin: '22px 0 0',
                    fontSize: size,
                    lineHeight: 1.06,
                    fontWeight: 600,
                    letterSpacing: -1.5,
                    color: C.ink,
                }}
            >
                <Words text={title} at={at + 0.12} />
            </h1>
            <div style={{ position: 'relative', marginTop: 30, height: 120 }}>
                {subs.map(([text, start], i) => {
                    const end = subs[i + 1]?.[1] ?? 9999;
                    const o = visible(t, at + start, at + end, 0.45);

                    return (
                        <p
                            key={i}
                            style={{
                                position: 'absolute',
                                left: 0,
                                right: 0,
                                margin: 0,
                                fontSize: 32,
                                lineHeight: 1.4,
                                color: C.ink2,
                                opacity: o,
                                transform: `translateY(${(1 - o) * (t < at + start + 0.5 ? 18 : -18)}px)`,
                            }}
                        >
                            {text}
                        </p>
                    );
                })}
            </div>
        </div>
    );
};

/** A browser window at 1440 x 900, in perspective. */
export const BrowserWindow = ({
    children,
    x,
    y,
    scale,
    rotateY = 0,
    rotateX = 0,
    opacity = 1,
    url = 'lumberjack.nordicgoods.com',
}: {
    children: ReactNode;
    x: number;
    y: number;
    scale: number;
    rotateY?: number;
    rotateX?: number;
    opacity?: number;
    url?: string;
}) => (
    <div style={{ position: 'absolute', inset: 0, perspective: 2400, opacity }}>
        <div
            style={{
                position: 'absolute',
                left: x - 720,
                top: y - 472,
                width: 1440,
                transform: `scale(${scale}) rotateY(${rotateY}deg) rotateX(${rotateX}deg)`,
                transformStyle: 'preserve-3d',
                borderRadius: 16,
                overflow: 'hidden',
                background: C.ground,
                border: `1px solid ${C.lineStrong}`,
                boxShadow:
                    '0 60px 140px rgba(0,0,0,0.65), 0 0 0 1px rgba(255,255,255,0.03), 0 0 120px rgba(240,169,59,0.08)',
            }}
        >
            <div
                style={{
                    height: 44,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 9,
                    padding: '0 18px',
                    background: '#15181b',
                    borderBottom: `1px solid ${C.line}`,
                }}
            >
                {['#ff5f57', '#febc2e', '#28c840'].map((c) => (
                    <i
                        key={c}
                        style={{
                            width: 13,
                            height: 13,
                            borderRadius: 7,
                            background: c,
                            display: 'block',
                            opacity: 0.85,
                        }}
                    />
                ))}
                <span
                    style={{
                        margin: '0 auto',
                        padding: '6px 22px',
                        borderRadius: 8,
                        background: C.panel2,
                        color: C.ink3,
                        fontSize: 16,
                        fontFamily: FONT,
                    }}
                >
                    {url}
                </span>
            </div>
            <div
                style={{
                    position: 'relative',
                    height: 900,
                    overflow: 'hidden',
                }}
            >
                {children}
            </div>
        </div>
    </div>
);

/** Moves the page inside a window: the point (x, y) of the page ends up centered, at `s`. */
export const Camera = ({
    x,
    y,
    s,
    height = 900,
    children,
}: {
    x: number;
    y: number;
    s: number;
    height?: number;
    children: ReactNode;
}) => {
    const cx = Math.min(Math.max(x, 720 / s), 1440 - 720 / s);
    const cy = Math.min(Math.max(y, 450 / s), height - 450 / s);

    return (
        <div
            style={{
                position: 'absolute',
                left: 0,
                top: 0,
                width: 1440,
                transformOrigin: '0 0',
                transform: `translate(${720 - cx * s}px, ${450 - cy * s}px) scale(${s})`,
            }}
        >
            {children}
        </div>
    );
};

type NavItem = { name: string; count?: number; children?: string[] };

/** The sidebar as the app draws it (resources/js/components/lj/sidebar.tsx). */
const PROJECT_NAV: NavItem[] = [
    { name: 'Overview' },
    { name: 'Issues', count: 7 },
    { name: 'Requests' },
    { name: 'Database' },
    { name: 'Browser' },
    { name: 'Jobs', count: 1 },
    { name: 'Console', children: ['Schedule', 'Commands'] },
    { name: 'Logs' },
    { name: 'Activity' },
    { name: 'AI usage', children: ['Usage', 'Budgets', 'Prices'] },
    { name: 'Health', count: 1 },
    { name: 'Releases' },
    { name: 'Connectors' },
    { name: 'Settings' },
];
const TEAM_NAV: NavItem[] = [
    { name: 'Projects' },
    { name: 'Screens', children: [] },
    { name: 'Alerts' },
    { name: 'Team', children: [] },
];

const NavRow = ({ item, active }: { item: NavItem; active: string }) => {
    const open = item.children?.includes(active) ?? false;
    const on = item.name === active || open;

    return (
        <>
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    height: 34,
                    padding: '0 10px',
                    borderRadius: 7,
                    fontSize: 15,
                    color: on ? C.ink : C.ink2,
                    background: on ? C.panel2 : undefined,
                    boxShadow: on ? `inset 3px 0 0 ${C.amber}` : undefined,
                }}
            >
                {item.name}
                {item.count ? (
                    <span
                        style={{
                            marginLeft: 'auto',
                            color: C.err,
                            fontSize: 13,
                        }}
                    >
                        {item.count}
                    </span>
                ) : null}
                {item.children ? (
                    <span
                        style={{
                            marginLeft: item.count ? 10 : 'auto',
                            color: C.ink3,
                            fontSize: 13,
                            transform: open ? 'rotate(90deg)' : undefined,
                        }}
                    >
                        ›
                    </span>
                ) : null}
            </div>
            {open
                ? item.children?.map((child) => (
                      <div
                          key={child}
                          style={{
                              height: 30,
                              display: 'flex',
                              alignItems: 'center',
                              paddingLeft: 26,
                              fontSize: 14,
                              color: child === active ? C.ink : C.ink3,
                          }}
                      >
                          {child}
                      </div>
                  ))
                : null}
        </>
    );
};

/** The app around a page: sidebar, top bar with breadcrumbs, content. */
export const AppShell = ({
    active,
    crumbs,
    children,
    height = 900,
    period = true,
    tabs,
}: {
    active: string;
    crumbs: string[];
    children: ReactNode;
    height?: number;
    period?: boolean;
    tabs?: { items: string[]; active: string };
}) => (
    <div
        style={{
            display: 'flex',
            width: 1440,
            minHeight: height,
            background: C.ground,
            fontFamily: FONT,
            color: C.ink,
        }}
    >
        <aside
            style={{
                width: 232,
                flexShrink: 0,
                background: C.rail,
                borderRight: `1px solid ${C.line}`,
                padding: '16px 12px',
                display: 'flex',
                flexDirection: 'column',
            }}
        >
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    fontWeight: 600,
                    fontSize: 17,
                    padding: '2px 8px',
                }}
            >
                <Logo size={24} box={false} /> Lumberjack
                <span
                    style={{
                        marginLeft: 'auto',
                        position: 'relative',
                        color: C.ink3,
                        fontSize: 16,
                    }}
                >
                    <svg
                        width="18"
                        height="18"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        style={{ display: 'block' }}
                    >
                        <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                        <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                    </svg>
                    <i
                        style={{
                            position: 'absolute',
                            right: -7,
                            top: -6,
                            minWidth: 15,
                            height: 15,
                            borderRadius: 8,
                            background: C.amber,
                            color: C.amberInk,
                            fontSize: 10,
                            fontStyle: 'normal',
                            display: 'grid',
                            placeItems: 'center',
                            fontWeight: 700,
                        }}
                    >
                        4
                    </i>
                </span>
            </div>
            <div style={{ ...field, marginTop: 18, color: C.ink3 }}>
                Search
                <kbd
                    style={{
                        marginLeft: 'auto',
                        fontFamily: MONO,
                        fontSize: 11,
                        border: `1px solid ${C.line}`,
                        borderRadius: 4,
                        padding: '1px 5px',
                    }}
                >
                    Ctrl K
                </kbd>
            </div>
            <div style={{ ...field, marginTop: 8 }}>
                <span
                    style={{
                        background: '#3a2a10',
                        color: C.amber,
                        borderRadius: 4,
                        padding: '0 6px',
                        fontSize: 12,
                        fontWeight: 600,
                    }}
                >
                    N
                </span>{' '}
                Nordic Goods
                <span style={{ marginLeft: 'auto', color: C.ink3 }}>⇕</span>
            </div>
            <div
                style={{
                    ...field,
                    marginTop: 8,
                    fontFamily: MONO,
                    fontSize: 14,
                }}
            >
                Storefront
                <span
                    style={{
                        marginLeft: 'auto',
                        color: C.ink3,
                        fontFamily: FONT,
                    }}
                >
                    ⇕
                </span>
            </div>
            <div
                style={{
                    marginTop: 20,
                    fontSize: 12,
                    letterSpacing: 1,
                    color: C.ink3,
                    padding: '0 10px 6px',
                }}
            >
                PROJECT
            </div>
            {PROJECT_NAV.map((item) => (
                <NavRow key={item.name} item={item} active={active} />
            ))}
            <div
                style={{
                    marginTop: 18,
                    fontSize: 12,
                    letterSpacing: 1,
                    color: C.ink3,
                    padding: '0 10px 6px',
                }}
            >
                TEAM
            </div>
            {TEAM_NAV.map((item) => (
                <NavRow key={item.name} item={item} active={active} />
            ))}
        </aside>
        <main style={{ flex: 1, minWidth: 0 }}>
            <div
                style={{
                    height: 56,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    padding: '0 28px',
                    borderBottom: `1px solid ${C.line}`,
                    fontSize: 15,
                    color: C.ink3,
                }}
            >
                {crumbs.map((c, i) => (
                    <span
                        key={c}
                        style={{
                            color: i === crumbs.length - 1 ? C.ink : undefined,
                            fontWeight: i === crumbs.length - 1 ? 600 : 400,
                            fontFamily: i === 1 ? MONO : undefined,
                            fontSize: i === 1 ? 14 : undefined,
                        }}
                    >
                        {i ? (
                            <span
                                style={{
                                    marginRight: 10,
                                    color: C.lineStrong,
                                    fontFamily: FONT,
                                }}
                            >
                                /
                            </span>
                        ) : null}
                        {c}
                    </span>
                ))}
                {period ? (
                    <>
                        <span
                            style={{
                                marginLeft: 'auto',
                                padding: '6px 12px',
                                border: `1px solid ${C.line}`,
                                borderRadius: 7,
                                color: C.ink,
                                fontSize: 14,
                                width: 160,
                                display: 'flex',
                            }}
                        >
                            All environments
                            <span style={{ marginLeft: 'auto', color: C.ink3 }}>
                                ⌄
                            </span>
                        </span>
                        <span
                            style={{
                                display: 'flex',
                                border: `1px solid ${C.line}`,
                                borderRadius: 7,
                                overflow: 'hidden',
                                fontSize: 13,
                            }}
                        >
                            {['1 h', '24 h', '7 d', '30 d'].map((p) => (
                                <span
                                    key={p}
                                    style={{
                                        padding: '6px 11px',
                                        background:
                                            p === '24 h' ? C.panel2 : undefined,
                                        color: p === '24 h' ? C.ink : C.ink3,
                                    }}
                                >
                                    {p}
                                </span>
                            ))}
                        </span>
                    </>
                ) : null}
            </div>
            {tabs ? (
                <div
                    style={{
                        display: 'flex',
                        gap: 28,
                        padding: '0 28px',
                        borderBottom: `1px solid ${C.line}`,
                        height: 44,
                        alignItems: 'stretch',
                        fontSize: 15,
                    }}
                >
                    {tabs.items.map((tab) => (
                        <span
                            key={tab}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                color: tab === tabs.active ? C.ink : C.ink3,
                                fontWeight: tab === tabs.active ? 600 : 400,
                                boxShadow:
                                    tab === tabs.active
                                        ? `inset 0 -2px 0 ${C.amber}`
                                        : undefined,
                            }}
                        >
                            {tab}
                        </span>
                    ))}
                </div>
            ) : null}
            <div style={{ padding: '24px 28px' }}>{children}</div>
        </main>
    </div>
);

/** The title of a page, the line under it, and buttons on the right. */
export const PageHeader = ({
    title,
    subtitle,
    aside,
}: {
    title: string;
    subtitle?: ReactNode;
    aside?: ReactNode;
}) => (
    <div
        style={{
            display: 'flex',
            alignItems: 'flex-end',
            gap: 16,
            marginBottom: 20,
        }}
    >
        <div>
            <h2 style={{ margin: 0, fontSize: 25, fontWeight: 600 }}>
                {title}
            </h2>
            {subtitle ? (
                <div
                    style={{
                        marginTop: 6,
                        fontSize: 15,
                        color: C.ink2,
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                    }}
                >
                    {subtitle}
                </div>
            ) : null}
        </div>
        {aside ? (
            <div style={{ marginLeft: 'auto', display: 'flex', gap: 10 }}>
                {aside}
            </div>
        ) : null}
    </div>
);

/** A card's title with the hint line under it, as the app's tables have. */
export const CardHead = ({
    title,
    hint,
    aside,
}: {
    title: string;
    hint?: string;
    aside?: ReactNode;
}) => (
    <div
        style={{
            display: 'flex',
            alignItems: 'center',
            padding: '14px 18px',
            borderBottom: `1px solid ${C.line}`,
        }}
    >
        <div>
            <div style={{ fontWeight: 600, fontSize: 15 }}>{title}</div>
            {hint ? (
                <div style={{ marginTop: 3, fontSize: 13, color: C.ink3 }}>
                    {hint}
                </div>
            ) : null}
        </div>
        {aside ? <div style={{ marginLeft: 'auto' }}>{aside}</div> : null}
    </div>
);

/** Column headers: [label, width or flex, align]. */
export type Col = [label: string, width: number | 'flex', align?: 'right'];

export const THead = ({ cols }: { cols: Col[] }) => (
    <div
        style={{
            display: 'flex',
            gap: 14,
            padding: '10px 18px',
            fontSize: 12,
            letterSpacing: 0.8,
            color: C.ink3,
            borderBottom: `1px solid ${C.line}`,
        }}
    >
        {cols.map(([label, width, align]) => (
            <span key={label} style={{ ...colStyle(width), textAlign: align }}>
                {label.toUpperCase()}
            </span>
        ))}
    </div>
);

export const colStyle = (width: number | 'flex'): CSSProperties =>
    width === 'flex' ? { flex: 1, minWidth: 0 } : { width, flexShrink: 0 };

export const Button = ({
    children,
    primary = false,
}: {
    children: ReactNode;
    primary?: boolean;
}) => (
    <span
        style={{
            padding: '8px 14px',
            borderRadius: 8,
            fontSize: 15,
            fontWeight: primary ? 600 : 500,
            background: primary ? C.amber : C.panel,
            color: primary ? C.amberInk : C.ink,
            border: `1px solid ${primary ? C.amber : C.lineStrong}`,
            whiteSpace: 'nowrap',
        }}
    >
        {children}
    </span>
);

const field: CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    gap: 8,
    padding: '9px 11px',
    border: `1px solid ${C.line}`,
    borderRadius: 8,
    fontSize: 15,
    background: C.panel,
};

export const Card = ({
    title,
    aside,
    children,
    style,
}: {
    title?: string;
    aside?: ReactNode;
    children: ReactNode;
    style?: CSSProperties;
}) => (
    <section
        style={{
            background: C.panel,
            border: `1px solid ${C.line}`,
            borderRadius: 12,
            overflow: 'hidden',
            ...style,
        }}
    >
        {title ? (
            <header
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    padding: '14px 18px',
                    borderBottom: `1px solid ${C.line}`,
                    fontWeight: 600,
                    fontSize: 15,
                }}
            >
                {title}
                <span
                    style={{
                        marginLeft: 'auto',
                        fontWeight: 400,
                        fontSize: 13,
                        color: C.ink3,
                    }}
                >
                    {aside}
                </span>
            </header>
        ) : null}
        {children}
    </section>
);

export const Badge = ({
    children,
    t = 'muted',
    mono = false,
}: {
    children: ReactNode;
    t?: Tone;
    mono?: boolean;
}) => {
    const c = tone(t);

    return (
        <span
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 6,
                padding: '2px 8px',
                borderRadius: 6,
                fontSize: 13,
                fontWeight: 500,
                fontFamily: mono ? MONO : undefined,
                color: c.fg,
                background: c.bg,
                border: `1px solid ${c.line}`,
                whiteSpace: 'nowrap',
            }}
        >
            {children}
        </span>
    );
};

export const Dot = ({ t = 'ok', size = 8 }: { t?: Tone; size?: number }) => (
    <i
        style={{
            display: 'inline-block',
            width: size,
            height: size,
            borderRadius: size,
            background: tone(t).fg,
            flexShrink: 0,
        }}
    />
);

export const Stat = ({
    label,
    value,
    note,
    color = C.ink,
    style,
}: {
    label: string;
    value: ReactNode;
    note?: string;
    color?: string;
    style?: CSSProperties;
}) => (
    <div
        style={{
            background: C.panel,
            border: `1px solid ${C.line}`,
            borderRadius: 12,
            padding: '16px 18px',
            ...style,
        }}
    >
        <div
            style={{
                fontSize: 12,
                letterSpacing: 1,
                color: C.ink3,
                textTransform: 'uppercase',
            }}
        >
            {label}
        </div>
        <div
            style={{
                marginTop: 8,
                fontFamily: MONO,
                fontSize: 32,
                color,
                fontWeight: 500,
            }}
        >
            {value}
        </div>
        {note ? (
            <div
                style={{
                    marginTop: 6,
                    fontSize: 13,
                    color: C.ink3,
                    lineHeight: 1.4,
                }}
            >
                {note}
            </div>
        ) : null}
    </div>
);

/** Bars that grow from the floor, one after the other. */
export const Bars = ({
    values,
    at,
    height,
    color = '#3a424b',
    highlight,
    gap = 6,
    duration = 1.2,
}: {
    values: number[];
    at: number;
    height: number;
    color?: string | ((i: number) => string);
    highlight?: number;
    gap?: number;
    duration?: number;
}) => {
    const t = useTime();
    const max = Math.max(...values);

    return (
        <div style={{ display: 'flex', alignItems: 'flex-end', gap, height }}>
            {values.map((v, i) => {
                const p = progress(
                    t,
                    at + (i / values.length) * duration,
                    0.6,
                    easeBack,
                );

                return (
                    <div
                        key={i}
                        style={{
                            flex: 1,
                            height: Math.max(3, (v / max) * height * p),
                            borderRadius: '4px 4px 1px 1px',
                            background:
                                i === highlight
                                    ? C.err
                                    : typeof color === 'function'
                                      ? color(i)
                                      : color,
                        }}
                    />
                );
            })}
        </div>
    );
};

/** A line chart that draws itself from left to right. */
export const Line = ({
    values,
    at,
    width,
    height,
    color = C.amber,
    duration = 1.6,
    fill = true,
    strokeWidth = 3,
    max,
}: {
    values: number[];
    at: number;
    width: number;
    height: number;
    color?: string;
    duration?: number;
    fill?: boolean;
    strokeWidth?: number;
    max?: number;
}) => {
    const t = useTime();
    const id = useId().replaceAll(':', '');
    const top = max ?? Math.max(...values) * 1.1;
    const points = values.map(
        (v, i) =>
            [
                (i / (values.length - 1)) * width,
                height - (v / top) * height,
            ] as const,
    );
    const d = points
        .map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`)
        .join(' ');
    const p = progress(t, at, duration, easeInOut);

    return (
        <svg
            width={width}
            height={height}
            style={{ overflow: 'visible', display: 'block' }}
        >
            <defs>
                <clipPath id={`clip${id}`}>
                    <rect width={width * p} height={height + 10} y={-5} />
                </clipPath>
                <linearGradient id={`grad${id}`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stopColor={color} stopOpacity="0.28" />
                    <stop offset="1" stopColor={color} stopOpacity="0" />
                </linearGradient>
            </defs>
            <g clipPath={`url(#clip${id})`}>
                {fill ? (
                    <path
                        d={`${d} L${width},${height} L0,${height} Z`}
                        fill={`url(#grad${id})`}
                    />
                ) : null}
                <path
                    d={d}
                    fill="none"
                    stroke={color}
                    strokeWidth={strokeWidth}
                    strokeLinejoin="round"
                    strokeLinecap="round"
                />
            </g>
        </svg>
    );
};

/** Lights up its children and dims the rest of the page, with a tag on top. */
export const Spot = ({
    at,
    out,
    label,
    children,
    style,
    pad = 6,
}: {
    at: number;
    out: number;
    label?: string;
    children: ReactNode;
    style?: CSSProperties;
    pad?: number;
}) => {
    const t = useTime();
    const o = visible(t, at, out, 0.4);
    const s = tween(t, at, 0.5, 1.04, 1, easeOut);

    return (
        <div
            style={{
                position: 'relative',
                zIndex: o > 0 ? 5 : undefined,
                ...style,
            }}
        >
            {children}
            <div
                style={{
                    position: 'absolute',
                    inset: -pad,
                    borderRadius: 10,
                    border: `2px solid ${C.amber}`,
                    boxShadow: `0 0 0 4000px rgba(8,10,12,${0.55 * o}), 0 0 40px rgba(240,169,59,${0.35 * o})`,
                    opacity: o,
                    transform: `scale(${s})`,
                    pointerEvents: 'none',
                }}
            >
                {label ? (
                    <span
                        style={{
                            position: 'absolute',
                            left: -2,
                            bottom: 'calc(100% + 8px)',
                            padding: '5px 12px',
                            borderRadius: 7,
                            background: C.amber,
                            color: C.amberInk,
                            fontFamily: FONT,
                            fontWeight: 600,
                            fontSize: 15,
                            whiteSpace: 'nowrap',
                        }}
                    >
                        {label}
                    </span>
                ) : null}
            </div>
        </div>
    );
};

export type CursorKey = { t: number; x: number; y: number; click?: boolean };

/** A mouse pointer that glides between points and clicks. */
export const Cursor = ({
    keys,
    show,
    hide,
}: {
    keys: CursorKey[];
    show: number;
    hide: number;
}) => {
    const t = useTime();
    let pos = keys[0];
    for (let i = 1; i < keys.length; i++) {
        const to = keys[i];
        if (t < to.t) {
            const from = keys[i - 1];
            const p = progress(t, from.t, to.t - from.t, easeInOut);
            pos = {
                t,
                x: from.x + (to.x - from.x) * p,
                y: from.y + (to.y - from.y) * p,
            };
            break;
        }
        pos = to;
    }
    const clicks = keys.filter((k) => k.click);
    const press = clicks.reduce(
        (m, k) => Math.max(m, 1 - Math.abs(t - k.t) / 0.12),
        0,
    );

    return (
        <>
            {clicks.map((k) => {
                const p = progress(t, k.t, 0.6);
                if (t < k.t || p >= 1) {
                    return null;
                }

                return (
                    <div
                        key={k.t}
                        style={{
                            position: 'absolute',
                            left: k.x - 30,
                            top: k.y - 30,
                            width: 60,
                            height: 60,
                            borderRadius: 30,
                            border: `3px solid ${C.amber}`,
                            opacity: 1 - p,
                            transform: `scale(${0.3 + p * 1.4})`,
                        }}
                    />
                );
            })}
            <svg
                viewBox="0 0 24 24"
                width={34}
                height={34}
                style={{
                    position: 'absolute',
                    left: pos.x - 6,
                    top: pos.y - 4,
                    opacity: visible(t, show, hide, 0.3),
                    transform: `scale(${1 - press * 0.18})`,
                    filter: 'drop-shadow(0 4px 8px rgba(0,0,0,0.5))',
                }}
            >
                <path
                    d="M4 3l15.5 8.2-6.6 1.9-2.6 6.4z"
                    fill="#fff"
                    stroke="#161A1E"
                    strokeWidth="1.4"
                    strokeLinejoin="round"
                />
            </svg>
        </>
    );
};

/** Fades and lifts children in at `at`. */
export const Rise = ({
    at,
    children,
    distance = 24,
    style,
}: {
    at: number;
    children: ReactNode;
    distance?: number;
    style?: CSSProperties;
}) => {
    const t = useTime();
    const p = progress(t, at, 0.7);

    return (
        <div
            style={{
                opacity: p,
                transform: `translateY(${(1 - p) * distance}px)`,
                ...style,
            }}
        >
            {children}
        </div>
    );
};

/**
 * A sound from video/public that may be missing: licensed tracks are used in the film but kept
 * out of the repository, so the film renders without them.
 */
export const optionalSound = (name: string): string | null =>
    getStaticFiles().some((file) => file.name === name)
        ? staticFile(name)
        : null;
