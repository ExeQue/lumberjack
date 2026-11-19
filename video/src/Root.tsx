import { Composition } from 'remotion';
import { FPS } from './anim';
import { Poster, POSTER_FRAME } from './Poster';
import { Promo, promoDuration } from './Promo';

export const Root = () => (
    <>
        <Composition
            id="Promo"
            component={Promo}
            durationInFrames={promoDuration}
            fps={FPS}
            width={1920}
            height={1080}
        />
        <Composition
            id="Poster"
            durationInFrames={POSTER_FRAME + 1}
            fps={FPS}
            component={Poster}
            width={1920}
            height={1080}
            defaultProps={{
                duration: `${Math.floor(promoDuration / FPS / 60)}:${String(Math.round(promoDuration / FPS) % 60).padStart(2, '0')}`,
            }}
        />
    </>
);
