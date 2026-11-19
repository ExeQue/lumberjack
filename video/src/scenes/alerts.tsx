import type { CSSProperties, ReactNode } from 'react';
import {
    AbsoluteFill,
    Img,
    spring,
    staticFile,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';
import { easeInOut, progress, sec, useTime } from '../anim';
import { FONT, MONO } from '../theme';
import { Headline } from '../ui';

const DURATION = 8;
export const ALERTS = sec(DURATION);

// What the drivers send for a health check that went down (app/Domains/Alerts/Drivers).
const TITLE = 'Health check down in Storefront';
const SUBJECT = 'Payments API';
const BODY = '503 Service Unavailable';
const FACTS: [string, string][] = [
    ['Status', 'Down'],
    ['Check', 'HTTP https://api.payments.example/health'],
    ['Environment', 'production'],
    ['Hosts', '-'],
];
const BUTTON = 'Open in Lumberjack';

const Avatar = ({
    size = 36,
    radius = 6,
}: {
    size?: number;
    radius?: number;
}) => (
    <Img
        src={staticFile('avatar.png')}
        style={{
            width: size,
            height: size,
            borderRadius: radius,
            flexShrink: 0,
        }}
    />
);

/** A message springing in at `at`. */
const Pop = ({
    at,
    children,
    style,
}: {
    at: number;
    children: ReactNode;
    style?: CSSProperties;
}) => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const s = spring({
        frame: frame - at * fps,
        fps,
        config: { damping: 15, stiffness: 120 },
    });

    return (
        <div
            style={{
                position: 'absolute',
                opacity: Math.min(1, s * 1.4),
                transform: `translateY(${(1 - s) * 60}px) scale(${0.94 + s * 0.06})`,
                ...style,
            }}
        >
            {children}
        </div>
    );
};

const Label = ({ children }: { children: string }) => (
    <div
        style={{
            fontFamily: MONO,
            fontSize: 15,
            letterSpacing: 2,
            textTransform: 'uppercase',
            color: '#8a939c',
            marginBottom: 10,
        }}
    >
        {children}
    </div>
);

const Slack = () => (
    <div
        style={{
            width: 560,
            padding: '18px 20px',
            borderRadius: 12,
            background: '#1a1d21',
            border: '1px solid #35373b',
            color: '#d1d2d3',
            fontFamily: FONT,
            boxShadow: '0 30px 80px rgba(0,0,0,0.5)',
        }}
    >
        <div style={{ display: 'flex', gap: 12 }}>
            <Avatar />
            <div style={{ minWidth: 0 }}>
                <div style={{ fontSize: 16 }}>
                    <b style={{ color: '#fff' }}>Lumberjack</b>{' '}
                    <span
                        style={{
                            fontSize: 11,
                            padding: '1px 4px',
                            borderRadius: 3,
                            background: '#35373b',
                        }}
                    >
                        APP
                    </span>{' '}
                    <span style={{ color: '#9a9b9e', fontSize: 13 }}>
                        3:01 AM
                    </span>
                </div>
                <div
                    style={{
                        marginTop: 4,
                        fontSize: 16,
                        fontWeight: 700,
                        color: '#1d9bd1',
                    }}
                >
                    {TITLE}
                </div>
                <code
                    style={{
                        display: 'inline-block',
                        marginTop: 4,
                        padding: '1px 5px',
                        borderRadius: 4,
                        border: '1px solid #35373b',
                        background: '#222529',
                        color: '#e8912d',
                        fontFamily: MONO,
                        fontSize: 14,
                    }}
                >
                    {SUBJECT}
                </code>
                <div style={{ marginTop: 8, fontSize: 15 }}>{BODY}</div>
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: '1fr 1fr',
                        gap: '8px 16px',
                        marginTop: 10,
                        fontSize: 14,
                    }}
                >
                    {FACTS.map(([k, v]) => (
                        <div
                            key={k}
                            style={{ minWidth: 0, overflowWrap: 'anywhere' }}
                        >
                            <b style={{ color: '#fff' }}>{k}</b>
                            <br />
                            {v}
                        </div>
                    ))}
                </div>
                <span
                    style={{
                        display: 'inline-block',
                        marginTop: 12,
                        padding: '5px 12px',
                        borderRadius: 6,
                        border: '1px solid #565856',
                        fontSize: 14,
                        fontWeight: 700,
                        color: '#fff',
                    }}
                >
                    {BUTTON}
                </span>
            </div>
        </div>
    </div>
);

const Teams = () => (
    <div
        style={{
            width: 520,
            borderRadius: 10,
            background: '#292929',
            color: '#d6d6d6',
            fontFamily: FONT,
            boxShadow: '0 30px 80px rgba(0,0,0,0.5)',
            overflow: 'hidden',
        }}
    >
        <div
            style={{
                display: 'flex',
                gap: 10,
                alignItems: 'center',
                padding: '12px 16px',
                fontSize: 14,
                color: '#adadad',
            }}
        >
            <span
                style={{
                    width: 28,
                    height: 28,
                    borderRadius: 14,
                    background: '#3d3e66',
                    display: 'grid',
                    placeItems: 'center',
                    color: '#fff',
                    fontSize: 12,
                    fontWeight: 600,
                }}
            >
                W
            </span>
            Workflows <span>3:01 AM</span>
        </div>
        <div
            style={{
                margin: '0 16px 16px',
                padding: '16px 18px',
                borderRadius: 8,
                background: '#1f1f1f',
                border: '1px solid #3b3b3b',
            }}
        >
            <div style={{ fontSize: 19, fontWeight: 700, color: '#ff8a8a' }}>
                {TITLE}
            </div>
            <div style={{ marginTop: 6, fontFamily: MONO, fontSize: 14 }}>
                {SUBJECT}
            </div>
            <div style={{ marginTop: 6, fontSize: 14, color: '#9e9e9e' }}>
                {BODY}
            </div>
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'auto 1fr',
                    gap: '5px 16px',
                    marginTop: 12,
                    fontSize: 14,
                }}
            >
                {FACTS.map(([k, v]) => (
                    <div key={k} style={{ display: 'contents' }}>
                        <b>{k}</b>
                        <span style={{ overflowWrap: 'anywhere' }}>{v}</span>
                    </div>
                ))}
            </div>
            <div
                style={{
                    marginTop: 14,
                    padding: '7px 0',
                    textAlign: 'center',
                    borderRadius: 4,
                    border: '1px solid #5b5fc7',
                    color: '#c7c9ff',
                    fontSize: 14,
                    fontWeight: 600,
                }}
            >
                {BUTTON}
            </div>
        </div>
    </div>
);

const Discord = () => (
    <div
        style={{
            width: 560,
            padding: '16px 18px',
            borderRadius: 10,
            background: '#313338',
            color: '#dbdee1',
            fontFamily: FONT,
            boxShadow: '0 30px 80px rgba(0,0,0,0.5)',
        }}
    >
        <div style={{ display: 'flex', gap: 14 }}>
            <Avatar size={40} radius={20} />
            <div style={{ minWidth: 0, flex: 1 }}>
                <div style={{ fontSize: 16 }}>
                    <b style={{ color: '#fff' }}>Lumberjack</b>{' '}
                    <span
                        style={{
                            fontSize: 11,
                            padding: '1px 5px',
                            borderRadius: 3,
                            background: '#5865f2',
                            color: '#fff',
                        }}
                    >
                        APP
                    </span>{' '}
                    <span style={{ color: '#949ba4', fontSize: 13 }}>
                        Today at 3:01 AM
                    </span>
                </div>
                <div
                    style={{
                        marginTop: 8,
                        display: 'flex',
                        borderRadius: 4,
                        background: '#2b2d31',
                        overflow: 'hidden',
                    }}
                >
                    <i
                        style={{
                            width: 4,
                            background: '#f2705f',
                            display: 'block',
                            flexShrink: 0,
                        }}
                    />
                    <div style={{ padding: '10px 14px', minWidth: 0 }}>
                        <div
                            style={{
                                fontSize: 15,
                                fontWeight: 700,
                                color: '#00a8fc',
                            }}
                        >
                            {TITLE}
                        </div>
                        <code
                            style={{
                                display: 'inline-block',
                                marginTop: 6,
                                padding: '1px 5px',
                                borderRadius: 4,
                                background: '#1e1f22',
                                fontFamily: MONO,
                                fontSize: 13,
                            }}
                        >
                            {SUBJECT}
                        </code>
                        <div style={{ marginTop: 4, fontSize: 14 }}>{BODY}</div>
                        <div
                            style={{
                                display: 'grid',
                                gridTemplateColumns: 'repeat(3, auto)',
                                gap: '8px 18px',
                                marginTop: 10,
                                fontSize: 13,
                            }}
                        >
                            {FACTS.map(([k, v]) => (
                                <div
                                    key={k}
                                    style={{
                                        minWidth: 0,
                                        overflowWrap: 'anywhere',
                                    }}
                                >
                                    <b style={{ color: '#fff' }}>{k}</b>
                                    <br />
                                    {v}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
                <span
                    style={{
                        display: 'inline-block',
                        marginTop: 8,
                        padding: '6px 14px',
                        borderRadius: 4,
                        background: '#4e5058',
                        fontSize: 14,
                        fontWeight: 600,
                        color: '#fff',
                    }}
                >
                    {BUTTON} ↗
                </span>
            </div>
        </div>
    </div>
);

/** A push on a phone's lock screen from ntfy: title, then subject and body. */
const Push = () => (
    <div
        style={{
            width: 500,
            padding: '14px 16px',
            borderRadius: 22,
            background: 'rgba(60,64,70,0.82)',
            color: '#fff',
            fontFamily: FONT,
            boxShadow: '0 30px 80px rgba(0,0,0,0.5)',
        }}
    >
        <div
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                fontSize: 13,
                color: '#c9cdd2',
            }}
        >
            <span
                style={{
                    width: 20,
                    height: 20,
                    borderRadius: 5,
                    background: '#338574',
                    display: 'grid',
                    placeItems: 'center',
                    fontSize: 11,
                    fontWeight: 700,
                }}
            >
                n
            </span>
            ntfy · storefront-alerts{' '}
            <span style={{ marginLeft: 'auto' }}>now</span>
        </div>
        <div style={{ marginTop: 8, fontSize: 17, fontWeight: 600 }}>
            {TITLE}
        </div>
        <div style={{ marginTop: 2, fontSize: 15, color: '#e1e4e8' }}>
            {SUBJECT}
            <br />
            {BODY}
        </div>
    </div>
);

export const Alerts = () => {
    const t = useTime();
    const leave = progress(t, DURATION - 0.9, 0.9, easeInOut);

    return (
        <AbsoluteFill>
            <div style={{ position: 'absolute', top: 300, left: 110 }}>
                <Headline
                    kicker="Alerts"
                    title="Where your team *already is*"
                    at={0.2}
                    out={DURATION - 0.95}
                    width={540}
                    size={68}
                    subs={[
                        [
                            'The same alert in Slack, Teams, Discord, or as a push.',
                            0.8,
                        ],
                        [
                            'With the check, the status, and a link straight in.',
                            4.2,
                        ],
                    ]}
                />
            </div>
            <div
                style={{
                    position: 'absolute',
                    inset: 0,
                    opacity: 1 - leave,
                    transform: `translateY(${-leave * 30}px)`,
                }}
            >
                <Pop at={0.5} style={{ left: 760, top: 90 }}>
                    <Label>Slack</Label>
                    <Slack />
                </Pop>
                <Pop at={1.2} style={{ left: 1350, top: 150 }}>
                    <Label>Microsoft Teams</Label>
                    <Teams />
                </Pop>
                <Pop at={1.9} style={{ left: 800, top: 560 }}>
                    <Label>Discord</Label>
                    <Discord />
                </Pop>
                <Pop at={2.6} style={{ left: 1390, top: 690 }}>
                    <Label>ntfy</Label>
                    <Push />
                </Pop>
            </div>
        </AbsoluteFill>
    );
};
