import type { ReactNode } from 'react';
import {
    AbsoluteFill,
    spring,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';
import { easeInOut, progress, sec, useTime } from '../anim';
import { C, FONT, MONO } from '../theme';
import { Badge, Headline } from '../ui';

const DURATION = 12;
export const MCP = sec(DURATION);

const PROMPT = 'Look at the newest issue in Lumberjack and fix it';

type LineDef = { at: number; node: ReactNode };

const Tool = ({
    server,
    name,
    arg,
}: {
    server: string;
    name: string;
    arg: string;
}) => (
    <span>
        <span style={{ color: C.ok }}>●</span>{' '}
        <span style={{ color: C.ink3 }}>{server} ·</span>{' '}
        <span style={{ color: C.ink }}>{name}</span>{' '}
        <span style={{ color: C.info }}>{arg}</span>
    </span>
);

const LINES: LineDef[] = [
    {
        at: 1.9,
        node: <Tool server="lumberjack" name="triage_inbox" arg="Storefront" />,
    },
    {
        at: 2.3,
        node: <Tool server="lumberjack" name="get_issue" arg="SHOP-14" />,
    },
    {
        at: 2.7,
        node: (
            <Tool server="lumberjack" name="get_trace" arg="5f50659…aa5c65" />
        ),
    },
    {
        at: 3.2,
        node: (
            <span style={{ color: C.ink2 }}>
                {'  '}The payment API times out after 5 s, and the cart{'\n'}
                {'  '}loads its products one query at a time (24 queries).
            </span>
        ),
    },
    {
        at: 4.4,
        node: (
            <span>
                <span style={{ color: C.ok }}>●</span> Edit{' '}
                <span style={{ color: C.ink }}>
                    app/Services/PaymentGateway.php
                </span>{' '}
                <span style={{ color: C.ok }}>+4</span>{' '}
                <span style={{ color: C.err }}>−1</span>
            </span>
        ),
    },
    {
        at: 4.9,
        node: (
            <span>
                <span style={{ color: C.ok }}>●</span> Edit{' '}
                <span style={{ color: C.ink }}>
                    app/Http/Controllers/CheckoutController.php
                </span>{' '}
                <span style={{ color: C.ok }}>+1</span>{' '}
                <span style={{ color: C.err }}>−1</span>
            </span>
        ),
    },
    {
        at: 5.6,
        node: (
            <span>
                <span style={{ color: C.ok }}>●</span> Bash{' '}
                <span style={{ color: C.ink }}>gh pr create --draft</span>{' '}
                <span style={{ color: C.info }}>"Fixes SHOP-14"</span>
            </span>
        ),
    },
    {
        at: 6.2,
        node: (
            <Tool
                server="lumberjack"
                name="add_issue_note"
                arg="SHOP-14 · PR #212"
            />
        ),
    },
    {
        at: 7.0,
        node: (
            <span style={{ color: C.amber }}>
                ✓ Draft PR #212 is open, and SHOP-14 has a note.
            </span>
        ),
    },
];

const Terminal = () => {
    const t = useTime();
    const typed = Math.floor(progress(t, 0.7, 1.0, (n) => n) * PROMPT.length);
    const caret = Math.floor(t * 2) % 2 === 0;

    return (
        <div
            style={{
                width: 1000,
                height: 520,
                borderRadius: 16,
                overflow: 'hidden',
                background: '#0b0d0f',
                border: `1px solid ${C.lineStrong}`,
                boxShadow:
                    '0 60px 140px rgba(0,0,0,0.65), 0 0 120px rgba(240,169,59,0.08)',
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
                        color: C.ink3,
                        fontSize: 16,
                        fontFamily: FONT,
                    }}
                >
                    agent — ~/code/storefront
                </span>
            </div>
            <div
                style={{
                    padding: '24px 28px',
                    fontFamily: MONO,
                    fontSize: 20,
                    lineHeight: 1.65,
                    color: C.ink,
                    whiteSpace: 'pre',
                }}
            >
                <div>
                    <span style={{ color: C.amber }}>❯ </span>
                    {PROMPT.slice(0, typed)}
                    {typed < PROMPT.length && caret ? (
                        <span style={{ background: C.ink }}> </span>
                    ) : null}
                </div>
                <div style={{ height: 14 }} />
                {LINES.map((line, i) => {
                    const p = progress(t, line.at, 0.35);

                    return (
                        <div
                            key={i}
                            style={{
                                opacity: p,
                                transform: `translateY(${(1 - p) * 10}px)`,
                            }}
                        >
                            {line.node}
                        </div>
                    );
                })}
            </div>
        </div>
    );
};

const NotePanel = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const s = spring({
        frame: frame - 7.4 * fps,
        fps,
        config: { damping: 15, stiffness: 110 },
    });

    return (
        <div
            style={{
                width: 640,
                borderRadius: 14,
                background: C.panel,
                border: `1px solid ${C.lineStrong}`,
                boxShadow: '0 40px 100px rgba(0,0,0,0.6)',
                fontFamily: FONT,
                color: C.ink,
                opacity: Math.min(1, s * 1.4),
                transform: `translateY(${(1 - s) * 80}px) scale(${0.92 + s * 0.08})`,
            }}
        >
            <div
                style={{
                    display: 'flex',
                    gap: 26,
                    padding: '12px 20px',
                    borderBottom: `1px solid ${C.line}`,
                    fontSize: 15,
                    color: C.ink3,
                }}
            >
                <span>Stacktrace</span>
                <span>Occurrences 312</span>
                <span
                    style={{
                        color: C.ink,
                        fontWeight: 600,
                        boxShadow: `0 15px 0 -13px ${C.amber}`,
                    }}
                >
                    Notes 1
                </span>
                <span>History</span>
            </div>
            <div style={{ padding: 16 }}>
                <div
                    style={{
                        border: `1px solid ${C.line}`,
                        borderRadius: 10,
                        padding: '12px 16px',
                    }}
                >
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            fontSize: 14,
                            color: C.ink3,
                        }}
                    >
                        <Badge t="info">MCP</Badge> Via MCP, token triage-bot,
                        just now
                    </div>
                    <p
                        style={{
                            margin: '10px 0 0',
                            fontSize: 16,
                            lineHeight: 1.5,
                            color: C.ink,
                        }}
                    >
                        Triage: v2.16.2 dropped the timeout and retry on the
                        payment client, and the cart loads its products one by
                        one. Draft PR:
                        github.com/nordicgoods/storefront/pull/212
                    </p>
                </div>
            </div>
        </div>
    );
};

export const Mcp = () => {
    const t = useTime();
    const enter = progress(t, 0.05, 1.2);
    const leave = progress(t, DURATION - 0.9, 0.9, easeInOut);

    return (
        <AbsoluteFill>
            <div style={{ position: 'absolute', top: 300, left: 110 }}>
                <Headline
                    kicker="MCP for AI agents"
                    title="Let an agent *fix* it"
                    at={0.25}
                    out={DURATION - 0.95}
                    width={560}
                    size={68}
                    subs={[
                        ['Agents read your issues and traces over MCP.', 0.9],
                        ['They find the cause and change the code.', 4.2],
                        ['Then leave a note and draft the pull request.', 7.3],
                    ]}
                />
            </div>
            <div
                style={{
                    position: 'absolute',
                    left: 760,
                    top: 150,
                    perspective: 2400,
                    opacity: enter * (1 - leave),
                    transform: `translateX(${(1 - enter) * 200}px)`,
                }}
            >
                <div
                    style={{
                        transform: `rotateY(${-6 - (1 - enter) * 10}deg)`,
                    }}
                >
                    <Terminal />
                </div>
            </div>
            <div
                style={{
                    position: 'absolute',
                    left: 1180,
                    top: 630,
                    opacity: 1 - leave,
                }}
            >
                <NotePanel />
            </div>
        </AbsoluteFill>
    );
};
