import type { ReactNode } from 'react';
import { AbsoluteFill } from 'remotion';
import { easeInOut, progress, useTime } from '../anim';
import { BrowserWindow, Headline, type Sub } from '../ui';

/** A headline on one side, the app in a browser window on the other. */
export const Feature = ({
    side = 'right',
    kicker,
    title,
    subs,
    duration,
    children,
    url,
}: {
    side?: 'left' | 'right';
    kicker: string;
    title: string;
    subs: Sub[];
    duration: number;
    children: ReactNode;
    url?: string;
}) => {
    const t = useTime();
    const enter = progress(t, 0.05, 1.3);
    const leave = progress(t, duration - 0.9, 0.9, easeInOut);
    const direction = side === 'right' ? 1 : -1;

    return (
        <AbsoluteFill>
            <BrowserWindow
                x={
                    (side === 'right' ? 1262 : 658) +
                    direction * (1 - enter) * 260 -
                    direction * leave * 120
                }
                y={540 + (1 - enter) * 40}
                scale={0.78 + leave * 0.03}
                rotateY={-direction * (9 + (1 - enter) * 10)}
                rotateX={2.5}
                opacity={enter * (1 - leave)}
                url={url}
            >
                {children}
            </BrowserWindow>
            <div
                style={{
                    position: 'absolute',
                    top: 300,
                    left: side === 'right' ? 110 : 1920 - 110 - 560,
                }}
            >
                <Headline
                    kicker={kicker}
                    title={title}
                    subs={subs}
                    at={0.25}
                    out={duration - 0.95}
                    width={560}
                    size={68}
                />
            </div>
        </AbsoluteFill>
    );
};

/** Swaps from one page to the next with a short dissolve at `at`. */
export const PageSwap = ({
    at,
    before,
    after,
}: {
    at: number;
    before: ReactNode;
    after: ReactNode;
}) => {
    const t = useTime();
    const p = progress(t, at, 0.45);

    return (
        <>
            {p < 1 ? (
                <div style={{ position: 'absolute', inset: 0 }}>{before}</div>
            ) : null}
            {p > 0 ? (
                <div style={{ position: 'absolute', inset: 0, opacity: p }}>
                    {after}
                </div>
            ) : null}
        </>
    );
};
