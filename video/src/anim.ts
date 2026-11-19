import { Easing, interpolate, useCurrentFrame, useVideoConfig } from 'remotion';

export const FPS = 60;

/** Seconds to frames. */
export const sec = (seconds: number) => Math.round(seconds * FPS);

/** The current time of the enclosing sequence, in seconds. */
export const useTime = () => {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();

    return frame / fps;
};

export const easeOut = Easing.bezier(0.16, 1, 0.3, 1);
export const easeInOut = Easing.bezier(0.65, 0, 0.35, 1);
export const easeBack = Easing.bezier(0.34, 1.56, 0.64, 1);

/** 0 → 1 between `start` and `start + duration` seconds, clamped. */
export const progress = (
    t: number,
    start: number,
    duration: number,
    easing: (n: number) => number = easeOut,
) =>
    interpolate(t, [start, start + duration], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing,
    });

/** `from` → `to` between `start` and `start + duration` seconds. */
export const tween = (
    t: number,
    start: number,
    duration: number,
    from: number,
    to: number,
    easing?: (n: number) => number,
) => from + (to - from) * progress(t, start, duration, easing);

/** Visible from `tIn` to `tOut`, fading at both ends. */
export const visible = (t: number, tIn: number, tOut: number, fade = 0.35) =>
    Math.min(progress(t, tIn, fade), 1 - progress(t, tOut, fade));

export type Key = { t: number; x: number; y: number; s: number };

/** Interpolates a camera (x, y, scale) through keyframes, easing in and out of each. */
export const camera = (t: number, keys: Key[], hold = 1.1) => {
    let from = keys[0];
    for (let i = 1; i < keys.length; i++) {
        const to = keys[i];
        if (t < to.t) {
            const p = progress(t, to.t - hold, hold, easeInOut);
            return {
                x: from.x + (to.x - from.x) * p,
                y: from.y + (to.y - from.y) * p,
                s: from.s + (to.s - from.s) * p,
            };
        }
        from = to;
    }

    return from;
};

/** A number counted up from 0 between `start` and `start + duration`. */
export const countUp = (
    t: number,
    start: number,
    duration: number,
    to: number,
) => to * progress(t, start, duration, easeOut);
