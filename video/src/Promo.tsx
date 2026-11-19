import { linearTiming, TransitionSeries } from '@remotion/transitions';
import { fade } from '@remotion/transitions/fade';
import { Fragment } from 'react';
import {
    AbsoluteFill,
    Audio,
    interpolate,
    Sequence,
    useCurrentFrame,
} from 'remotion';
import { sec } from './anim';
import { Alerts, ALERTS } from './scenes/alerts';
import { More, MORE, Outro, OUTRO } from './scenes/closing';
import { Health, HEALTH } from './scenes/health';
import { Issues, ISSUES } from './scenes/issues';
import { Mcp, MCP } from './scenes/mcp';
import { Hook, HOOK, Intro, INTRO } from './scenes/opening';
import { Overview, OVERVIEW } from './scenes/overview';
import { Performance, PERFORMANCE } from './scenes/performance';
import { AiUsage, Jobs, Logs, Screens, SHORT } from './scenes/shorts';
import { C, FONT } from './theme';
import { Backdrop, optionalSound } from './ui';

const SCENES = [
    { name: 'hook', component: Hook, duration: HOOK },
    { name: 'intro', component: Intro, duration: INTRO },
    { name: 'health', component: Health, duration: HEALTH },
    { name: 'alerts', component: Alerts, duration: ALERTS },
    { name: 'issues', component: Issues, duration: ISSUES },
    { name: 'performance', component: Performance, duration: PERFORMANCE },
    { name: 'mcp', component: Mcp, duration: MCP },
    { name: 'overview', component: Overview, duration: OVERVIEW },
    { name: 'jobs', component: Jobs, duration: SHORT },
    { name: 'logs', component: Logs, duration: SHORT },
    { name: 'ai', component: AiUsage, duration: SHORT },
    { name: 'screens', component: Screens, duration: SHORT },
    { name: 'more', component: More, duration: MORE },
    { name: 'outro', component: Outro, duration: OUTRO },
];

/**
 * The track under the film: "Electronic Tension" by leberch (Pixabay Content License). The
 * license allows it in the film but not on its own, so it is not in the repository: sounds.mjs
 * fetches it before a render. It starts as the logo draws and fades out on its own.
 */
const MUSIC = optionalSound('music.mp3');

const OVERLAP = sec(0.6);
// Overlaps the end of the alarm under the cold open by 0.8 s, fading in as the alarm fades out.
const MUSIC_START = HOOK - OVERLAP - sec(0.8);

export const promoDuration =
    SCENES.reduce((sum, scene) => sum + scene.duration, 0) -
    OVERLAP * (SCENES.length - 1);

export const Promo = () => {
    const frame = useCurrentFrame();

    return (
        <AbsoluteFill style={{ background: C.ground, fontFamily: FONT }}>
            <Backdrop
                glowX={960 + Math.sin(frame / 300) * 520}
                glowY={480 + Math.cos(frame / 420) * 220}
            />
            {MUSIC ? (
                <Sequence from={MUSIC_START}>
                    <Audio
                        src={MUSIC}
                        volume={(f) =>
                            interpolate(f, [0, sec(1.2)], [0, 0.9], {
                                extrapolateRight: 'clamp',
                            })
                        }
                    />
                </Sequence>
            ) : null}
            <TransitionSeries>
                {SCENES.map(({ name, component: Scene, duration }, i) => (
                    <Fragment key={name}>
                        {i ? (
                            <TransitionSeries.Transition
                                presentation={fade()}
                                timing={linearTiming({
                                    durationInFrames: OVERLAP,
                                })}
                            />
                        ) : null}
                        <TransitionSeries.Sequence
                            durationInFrames={duration}
                            name={name}
                        >
                            <Scene />
                        </TransitionSeries.Sequence>
                    </Fragment>
                ))}
            </TransitionSeries>
        </AbsoluteFill>
    );
};
