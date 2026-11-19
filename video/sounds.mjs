// Fetches the licensed sounds into public/ when they are missing. The Pixabay Content License
// allows them in the film but not handed out on their own, so they stay out of the repository.
import { existsSync } from 'node:fs';
import { writeFile } from 'node:fs/promises';

const SOUNDS = {
    // "Electronic Tension" by leberch: https://pixabay.com/music/mystery-electronic-tension-255438/
    'music.mp3':
        'https://cdn.pixabay.com/download/audio/2024/10/26/audio_454dd5420a.mp3?filename=leberch-electronic-tension-255438.mp3',
    // "Alarm Clock" by ecfike (Freesound): https://pixabay.com/sound-effects/alarm-clock-90867/
    'alarm.mp3':
        'https://cdn.pixabay.com/download/audio/2022/03/15/audio_32283e5329.mp3?filename=freesound_community-alarm-clock-90867.mp3',
};

for (const [name, url] of Object.entries(SOUNDS)) {
    const path = new URL(`./public/${name}`, import.meta.url);
    if (existsSync(path)) {
        continue;
    }

    const response = await fetch(url);
    if (!response.ok) {
        console.warn(
            `Could not fetch ${name} (${response.status}); the film renders without it.`,
        );
        continue;
    }

    await writeFile(path, Buffer.from(await response.arrayBuffer()));
    console.log(`Fetched ${name}`);
}
