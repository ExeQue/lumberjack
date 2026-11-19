import {
    AbsoluteFill,
    spring,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';
import { easeInOut, progress, sec, tween, useTime } from '../anim';
import { C, FONT, MONO } from '../theme';
import { Logo, Words } from '../ui';

const MORE_DURATION = 4.6;
export const MORE = sec(MORE_DURATION);

const PILLS = [
    'OpenTelemetry, any language',
    'Weekly summary',
    'Ctrl+K anywhere',
    'Maintenance windows',
    'Teams & roles',
    'Labels & mentions',
    'Source maps',
    'Web Vitals',
];

export const More = () => {
    const t = useTime();
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const leave = progress(t, MORE_DURATION - 0.8, 0.7, easeInOut);

    return (
        <AbsoluteFill
            style={{
                alignItems: 'center',
                justifyContent: 'center',
                fontFamily: FONT,
                opacity: 1 - leave,
                filter: leave ? `blur(${leave * 10}px)` : undefined,
            }}
        >
            <div
                style={{
                    fontFamily: MONO,
                    fontSize: 22,
                    letterSpacing: 3,
                    color: C.amber,
                    opacity: progress(t, 0.1, 0.5),
                }}
            >
                AND THERE IS MORE
            </div>
            <h1
                style={{
                    margin: '20px 0 0',
                    fontSize: 92,
                    fontWeight: 600,
                    letterSpacing: -2,
                    color: C.ink,
                }}
            >
                <Words text="Everything in *one* place" at={0.25} />
            </h1>
            <div
                style={{
                    display: 'flex',
                    flexWrap: 'wrap',
                    justifyContent: 'center',
                    gap: 18,
                    width: 1400,
                    marginTop: 64,
                }}
            >
                {PILLS.map((pill, i) => {
                    const s = spring({
                        frame: frame - (1.0 + i * 0.14) * fps,
                        fps,
                        config: { damping: 13, stiffness: 140 },
                    });

                    return (
                        <span
                            key={pill}
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 12,
                                padding: '16px 28px',
                                borderRadius: 40,
                                background: 'rgba(28,33,38,0.8)',
                                border: `1px solid ${C.lineStrong}`,
                                fontSize: 30,
                                color: C.ink,
                                opacity: Math.min(1, s * 1.5),
                                transform: `translateY(${(1 - s) * 40}px) scale(${0.8 + s * 0.2})`,
                            }}
                        >
                            <i
                                style={{
                                    width: 10,
                                    height: 10,
                                    borderRadius: 5,
                                    background: C.amber,
                                    display: 'block',
                                }}
                            />
                            {pill}
                        </span>
                    );
                })}
            </div>
        </AbsoluteFill>
    );
};

const OUTRO_DURATION = 6.1;
export const OUTRO = sec(OUTRO_DURATION);

const COMMAND =
    'curl -fsSL https://exeque.github.io/lumberjack/install.sh | sh';

export const Outro = () => {
    const t = useTime();
    const draw = progress(t, 0.1, 1.4, easeInOut);
    const box = progress(t, 1.3, 0.5);
    const typed = Math.floor(progress(t, 1.7, 1.4, (n) => n) * COMMAND.length);
    const caret = Math.floor(t * 2) % 2 === 0;
    const fadeOut = progress(t, OUTRO_DURATION - 0.8, 0.8, easeInOut);

    return (
        <AbsoluteFill
            style={{
                alignItems: 'center',
                fontFamily: FONT,
                opacity: 1 - fadeOut,
            }}
        >
            <div
                style={{
                    marginTop: 150,
                    transform: `scale(${tween(t, 0, 1.6, 0.9, 1)})`,
                    filter: `drop-shadow(0 0 ${36 * draw}px rgba(240,169,59,0.45))`,
                }}
            >
                <Logo size={130} draw={draw} box={false} />
            </div>
            <h1
                style={{
                    margin: '36px 0 0',
                    fontSize: 96,
                    fontWeight: 600,
                    letterSpacing: -2.5,
                    color: C.ink,
                }}
            >
                <Words text="Run it on your *own* server" at={0.6} />
            </h1>
            <div
                style={{
                    marginTop: 56,
                    padding: '26px 36px',
                    borderRadius: 16,
                    background: '#0b0d0f',
                    border: `1px solid ${C.lineStrong}`,
                    boxShadow: '0 40px 100px rgba(0,0,0,0.6)',
                    fontFamily: MONO,
                    fontSize: 30,
                    color: C.ink,
                    opacity: box,
                    transform: `translateY(${(1 - box) * 24}px)`,
                    minWidth: 1180,
                }}
            >
                <span style={{ color: C.amber }}>$ </span>
                {COMMAND.slice(0, typed)}
                <span
                    style={{
                        display: 'inline-block',
                        width: 16,
                        height: 34,
                        verticalAlign: 'middle',
                        marginLeft: 4,
                        background: caret ? C.ink : 'transparent',
                    }}
                />
            </div>
            <div
                style={{
                    display: 'flex',
                    gap: 22,
                    marginTop: 44,
                    fontSize: 30,
                    color: C.ink2,
                }}
            >
                {[
                    'Open source · MIT',
                    'One Docker image',
                    'Your data stays yours',
                ].map((item, i) => {
                    const p = progress(t, 3.2 + i * 0.2, 0.5);

                    return (
                        <span
                            key={item}
                            style={{
                                opacity: p,
                                transform: `translateY(${(1 - p) * 16}px)`,
                            }}
                        >
                            {i ? (
                                <span
                                    style={{
                                        color: C.lineStrong,
                                        marginRight: 22,
                                    }}
                                >
                                    /
                                </span>
                            ) : null}
                            {item}
                        </span>
                    );
                })}
            </div>
            <div
                style={{
                    marginTop: 56,
                    fontFamily: MONO,
                    fontSize: 36,
                    color: C.amber,
                    opacity: progress(t, 3.9, 0.6),
                }}
            >
                exeque.github.io/lumberjack
            </div>
        </AbsoluteFill>
    );
};
