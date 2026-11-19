import { AbsoluteFill } from 'remotion';
import { Intro } from './scenes/opening';
import { C, FONT } from './theme';
import { Backdrop } from './ui';

/** The still in front of the film in the README: the logo, its line, and a play button. Rendered at POSTER_FRAME. */
export const POSTER_FRAME = 252;

export const Poster = ({ duration }: { duration: string }) => (
    <AbsoluteFill style={{ background: C.ground, fontFamily: FONT }}>
        <Backdrop glowX={1240} glowY={520} />
        <Intro />
        <div
            style={{
                position: 'absolute',
                left: 0,
                right: 0,
                bottom: 70,
                display: 'flex',
                justifyContent: 'center',
            }}
        >
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 18,
                    padding: '14px 30px 14px 16px',
                    borderRadius: 50,
                    background: C.amber,
                    color: C.amberInk,
                    fontSize: 30,
                    fontWeight: 600,
                }}
            >
                <svg width="44" height="44" viewBox="0 0 44 44">
                    <circle cx="22" cy="22" r="22" fill={C.amberInk} />
                    <path d="M17 13l15 9-15 9z" fill={C.amber} />
                </svg>
                Watch the tour · {duration}
            </div>
        </div>
    </AbsoluteFill>
);
