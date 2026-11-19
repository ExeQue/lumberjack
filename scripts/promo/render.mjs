// Renders the promo video frame by frame: the stage page (stage.js, stage.css) draws
// each moment on request over the stills capture.mjs took, and the frames go straight
// into ffmpeg. Lumberjack does not have to run for it.
//
//   npm run docs:video                                 # capture, then render
//   node scripts/promo/render.mjs                      # the film -> storage/app/promo/lumberjack-promo.mp4
//   node scripts/promo/render.mjs --at 2,10.5,24       # single frames to look at -> storage/app/promo/at-<t>.jpg
//   node scripts/promo/render.mjs --fps 30 --from 18 --to 31
//
// FFMPEG_PATH        the ffmpeg to encode with (ffmpeg on the PATH); needs libx264
// CHROME_PATH        a Chrome or Chromium to use instead of the one Puppeteer downloaded
//
// To work on the film, open storage/app/promo/stage.html#play in a browser after one
// render (#play=31 starts at 31 s).
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { readFile, writeFile } from 'node:fs/promises';
import puppeteer from 'puppeteer';

const here = new URL('.', import.meta.url);
const OUT = new URL('../../storage/app/promo/', import.meta.url).pathname;
const arg = (name, fallback) => {
    const i = process.argv.indexOf(`--${name}`);
    return i > -1 ? process.argv[i + 1] : fallback;
};
const fps = Number(arg('fps', 60));
const at = arg('at', null);

const rects = await readFile(`${OUT}shots/rects.json`, 'utf8').catch(() => {
    throw new Error('No stills yet: run node scripts/promo/capture.mjs first.');
});
// The fonts the app itself uses, loaded the way its layout loads them.
await writeFile(
    `${OUT}stage.html`,
    `<!doctype html><meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="${new URL('stage.css', here)}">
<div id="stage"></div>
<script>const RECTS = ${rects};</script>
<script src="${new URL('stage.js', here)}"></script>
`,
);

const browser = await puppeteer.launch({
    executablePath: process.env.CHROME_PATH || undefined,
    args: ['--no-sandbox', '--allow-file-access-from-files'],
    defaultViewport: { width: 1920, height: 1080 },
});
const page = await browser.newPage();
page.on('pageerror', (error) => console.warn(`page error: ${error.message}`));
await page.goto(`file://${OUT}stage.html`, { waitUntil: 'networkidle0' });
await page.evaluate(async () => {
    await Promise.all([...document.images].map((img) => img.decode()));
    await Promise.all(
        ['400', '500', '600'].flatMap((weight) => [
            document.fonts.load(`${weight} 20px "IBM Plex Sans"`),
            document.fonts.load(`${weight} 20px "JetBrains Mono"`),
        ]),
    );
});
const duration = await page.evaluate(() => window.DURATION);
const frame = async (t, quality) => {
    await page.evaluate((t) => window.renderAt(t), t);
    return page.screenshot({ type: 'jpeg', quality });
};

if (at) {
    for (const t of at.split(',').map(Number)) {
        const path = `${OUT}at-${t.toFixed(1).padStart(5, '0')}.jpg`;
        await writeFile(path, await frame(t, 85));
        console.log(path);
    }
} else {
    const from = Number(arg('from', 0));
    const to = Number(arg('to', duration));
    const out = arg('out', `${OUT}lumberjack-promo.mp4`);
    const ffmpeg = spawn(
        process.env.FFMPEG_PATH || 'ffmpeg',
        // prettier-ignore
        ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-c:v', 'mjpeg', '-framerate', String(fps), '-i', '-',
            '-c:v', 'libx264', '-preset', 'slow', '-crf', '17', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', out],
        { stdio: ['pipe', 'ignore', 'inherit'] },
    );
    ffmpeg.on('error', (error) => {
        console.error(
            `Could not start ffmpeg (${error.message}). Install it, or point FFMPEG_PATH at one.`,
        );
        process.exit(1);
    });
    const total = Math.round((to - from) * fps);
    for (let i = 0; i < total; i++) {
        if (!ffmpeg.stdin.write(await frame(from + i / fps, 93))) {
            await once(ffmpeg.stdin, 'drain');
        }
        if (i % 600 === 0) {
            console.log(`${i} of ${total} frames`);
        }
    }
    ffmpeg.stdin.end();
    const [code] = await once(ffmpeg, 'close');
    if (code !== 0) {
        throw new Error(`ffmpeg ended with code ${code}.`);
    }
    console.log(out);
}
await browser.close();
