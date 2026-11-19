import {
    AbsoluteFill,
    Audio,
    interpolate,
    Sequence,
    spring,
    staticFile,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';
import { easeInOut, progress, sec, tween, useTime } from '../anim';
import { C, FONT, MONO } from '../theme';
import { Logo, optionalSound, Words } from '../ui';

/** An alarm clock under the cold open (Pixabay, kept out of the repository like the music). */
const ALARM = optionalSound('alarm.mp3');

const PINGS = [
    {
        at: 1.0,
        from: 'Support',
        text: '“I can’t pay for my order”',
        tone: C.ink2,
    },
    {
        at: 1.7,
        from: 'Support',
        text: 'Ticket #4821 · Checkout is broken',
        tone: C.ink2,
    },
    {
        at: 2.25,
        from: 'Finance',
        text: 'Revenue down 38% on the last hour',
        tone: C.warn,
    },
    { at: 2.65, from: 'CEO', text: 'Is the shop down??', tone: C.err },
];

/** Cold open: 03:02 at night and the messages pile up. */
export const Hook = () => {
    const t = useTime();
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const leave = progress(t, 5.2, 0.6, easeInOut);
    const minutes = t < 3.6 ? '02' : '03';

    return (
        <AbsoluteFill
            style={{
                fontFamily: FONT,
                opacity: 1 - leave,
                filter: leave ? `blur(${leave * 12}px)` : undefined,
            }}
        >
            {ALARM ? (
                <Audio
                    src={ALARM}
                    volume={(f) =>
                        interpolate(
                            f,
                            [0, sec(0.3), sec(4.6), sec(5.5)],
                            [0, 0.9, 0.9, 0],
                            { extrapolateRight: 'clamp' },
                        )
                    }
                />
            ) : null}
            {PINGS.map((ping, i) => (
                <Sequence
                    key={i}
                    from={Math.round(ping.at * fps)}
                    durationInFrames={fps}
                >
                    <Audio
                        src={staticFile('ping.wav')}
                        volume={0.55 + i * 0.1}
                    />
                </Sequence>
            ))}
            <div
                style={{
                    position: 'absolute',
                    left: 160,
                    top: 250,
                    fontFamily: MONO,
                    fontSize: 220,
                    fontWeight: 500,
                    letterSpacing: -6,
                    color: C.ink,
                    opacity: progress(t, 0.1, 0.8),
                }}
            >
                03:{minutes}
                <span
                    style={{
                        fontSize: 60,
                        color: C.ink3,
                        marginLeft: 20,
                        letterSpacing: 0,
                    }}
                >
                    AM
                </span>
            </div>
            <div
                style={{
                    position: 'absolute',
                    left: 168,
                    top: 540,
                    fontSize: 64,
                    fontWeight: 600,
                    color: C.ink,
                    letterSpacing: -1,
                }}
            >
                <Words text="Something broke." at={3.3} />
                <br />
                <Words text="Do you know *where?*" at={3.9} />
            </div>
            <div
                style={{
                    position: 'absolute',
                    right: 160,
                    top: 190,
                    width: 620,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 18,
                }}
            >
                {PINGS.map((ping, i) => {
                    const s = spring({
                        frame: frame - ping.at * fps,
                        fps,
                        config: { damping: 14, stiffness: 120 },
                    });

                    return (
                        <div
                            key={i}
                            style={{
                                display: 'flex',
                                gap: 18,
                                alignItems: 'center',
                                padding: '20px 24px',
                                borderRadius: 18,
                                background: 'rgba(28,33,38,0.85)',
                                border: `1px solid ${C.lineStrong}`,
                                boxShadow: '0 20px 50px rgba(0,0,0,0.45)',
                                opacity: Math.min(1, s * 1.5),
                                transform: `translateX(${(1 - s) * 140}px) scale(${0.9 + s * 0.1})`,
                            }}
                        >
                            <div
                                style={{
                                    width: 52,
                                    height: 52,
                                    borderRadius: 14,
                                    background: C.panel2,
                                    display: 'grid',
                                    placeItems: 'center',
                                    fontSize: 22,
                                    fontWeight: 600,
                                    color: ping.tone,
                                }}
                            >
                                {ping.from[0]}
                            </div>
                            <div>
                                <div style={{ fontSize: 18, color: C.ink3 }}>
                                    {ping.from}{' '}
                                    <span style={{ marginLeft: 8 }}>now</span>
                                </div>
                                <div
                                    style={{
                                        fontSize: 26,
                                        color:
                                            ping.tone === C.ink2
                                                ? C.ink
                                                : ping.tone,
                                        marginTop: 4,
                                    }}
                                >
                                    {ping.text}
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </AbsoluteFill>
    );
};

/** The logo draws itself in, the name and the tagline follow. */
export const Intro = () => {
    const t = useTime();
    const draw = progress(t, 0.2, 1.6, easeInOut);
    const lift = tween(t, 1.5, 0.9, 0, -70, easeInOut);
    const leave = progress(t, 5.0, 0.6, easeInOut);

    return (
        <AbsoluteFill
            style={{
                alignItems: 'center',
                justifyContent: 'center',
                fontFamily: FONT,
                opacity: 1 - leave,
                transform: `scale(${1 + leave * 0.08})`,
            }}
        >
            <div
                style={{
                    transform: `translateY(${lift}px) scale(${tween(t, 0, 2, 0.85, 1)})`,
                    filter: `drop-shadow(0 0 ${40 * draw}px rgba(240,169,59,0.45))`,
                }}
            >
                <Logo size={210} draw={draw} box={false} />
            </div>
            <div
                style={{
                    position: 'absolute',
                    top: 640,
                    fontSize: 120,
                    fontWeight: 600,
                    letterSpacing: -3,
                    color: C.ink,
                }}
            >
                <Words text="Lumberjack" at={1.6} />
            </div>
            <div
                style={{
                    position: 'absolute',
                    top: 800,
                    fontSize: 40,
                    color: C.ink2,
                }}
            >
                <Words
                    text="Every layer of your app, *ring* *by* *ring*"
                    at={2.4}
                    stagger={0.06}
                />
            </div>
        </AbsoluteFill>
    );
};

export const HOOK = sec(6);
export const INTRO = sec(6);
