// Captures the screenshots in docs/screenshots from a running Lumberjack.
//
//   npm run docs:screenshots                 # every shot
//   npm run docs:screenshots -- issues trace # only these
//
// LUMBERJACK_URL     where Lumberjack runs (https://lumberjack.test)
// DOCS_EMAIL         who signs in (the seeded admin@lumberjack)
// DOCS_PASSWORD      their password (password)
// DOCS_PROJECT       the project to show (the seeded Storefront)
// CHROME_PATH        a Chrome or Chromium to use instead of the one Puppeteer downloads
//
// It only reads: it opens pages and dialogs but never saves or submits anything.
import { mkdir } from 'node:fs/promises';
import puppeteer from 'puppeteer';

const BASE = (process.env.LUMBERJACK_URL ?? 'https://lumberjack.test').replace(
    /\/$/,
    '',
);
const EMAIL = process.env.DOCS_EMAIL ?? 'admin@lumberjack';
const PASSWORD = process.env.DOCS_PASSWORD ?? 'password';
const PROJECT = process.env.DOCS_PROJECT ?? 'Storefront';
const OUT = new URL('../docs/screenshots/', import.meta.url);

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const browser = await puppeteer.launch({
    executablePath: process.env.CHROME_PATH || undefined,
    acceptInsecureCerts: true,
    args: ['--no-sandbox'],
    defaultViewport: { width: 1440, height: 900 },
});
const page = await browser.newPage();
page.on('pageerror', (error) => console.warn(`page error: ${error.message}`));
let appearance = null;

const open = async (path, theme = 'dark') => {
    if (appearance) {
        await page.removeScriptToEvaluateOnNewDocument(appearance.identifier);
    }
    appearance = await page.evaluateOnNewDocument((mode) => {
        localStorage.setItem('appearance', mode);
    }, theme);
    // A live update, or the dev server reloading, can interrupt a page; try again.
    for (let attempt = 1; ; attempt++) {
        try {
            const response = await page.goto(BASE + path, {
                waitUntil: 'networkidle0',
            });
            if (response && response.status() >= 400) {
                throw new Error(`${path} answered HTTP ${response.status()}.`);
            }
            // The app renders in the browser; wait until it has.
            await page.waitForFunction(
                () => (document.body.innerText ?? '').trim().length > 50,
                { timeout: 15_000 },
            );
            break;
        } catch (error) {
            if (attempt === 3) {
                throw error;
            }
            await sleep(2000);
        }
    }
    // Charts animate in and live counters tick; give them a moment to settle.
    await sleep(800);
};

const click = async (selector) => {
    await page.waitForSelector(selector, { timeout: 10_000 });
    await page.click(selector);
    await sleep(600);
};

// Webhook URLs carry ids and tokens; show only where they point.
const mask = () =>
    page.evaluate(() => {
        const walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
        );
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            node.textContent = node.textContent.replace(
                /(https:\/\/[^/\s]+\/)\S*(webhooks?|hooks)\S*/g,
                '$1····',
            );
        }
    });

const href = async (selector) =>
    page.$eval(selector, (a) => new URL(a.href).pathname).catch(() => null);

// Sign in.
await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
await page.type('input[name=email]', EMAIL);
await page.type('input[name=password]', PASSWORD);
await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle0' }),
    page.click('button[type=submit]'),
]);
if (page.url().includes('/login')) {
    throw new Error(`Could not sign in at ${BASE} as ${EMAIL}.`);
}

// Find the project, an issue in it and a trace of that issue.
await open('/projects');
const project = await page.evaluate((name) => {
    const link = [...document.querySelectorAll('a[href*="/projects/"]')].find(
        (a) => a.textContent?.includes(name),
    );
    return link ? new URL(link.href).pathname : null;
}, PROJECT);
if (!project) {
    throw new Error(`No project named ${PROJECT} on /projects.`);
}
await open(`${project}/issues`);
// Issues with a stacktrace (the row shows a file and line), those with notes first.
const issues = await page.evaluate(() =>
    [...document.querySelectorAll('tbody tr')]
        .filter((row) => /\.php:\d+/.test(row.textContent ?? ''))
        .sort(
            (a, b) =>
                Number(!!b.querySelector('.lucide-message-square')) -
                Number(!!a.querySelector('.lucide-message-square')),
        )
        .map((row) => row.querySelector('a[href*="/issues/"]'))
        .filter(Boolean)
        .map((a) => new URL(a.href).pathname),
);
const issue = issues[0] ?? null;
// The trace of the first of them that has one.
let trace = null;
for (const candidate of issues) {
    await open(candidate);
    await click('#tab-occurrences');
    trace = await href('#panel-occurrences a[href*="/traces/"]');
    if (trace) {
        break;
    }
}

// The first status screen, for the designer and the kiosk.
await open('/present/screens');
const screen = await href('a[href$="/edit"]');
const kiosk = screen ? screen.replace(/\/edit$/, '') : null;
// The seeded widget gallery (ShowcaseWidgetsSeeder), one tile of each widget for docs/widgets.md.
const gallery = await page.evaluate(() => {
    const link = [...document.querySelectorAll('a[href$="/edit"]')].find(
        (a) => a.textContent?.trim() === 'Widget gallery',
    );
    return link ? new URL(link.href).pathname.replace(/\/edit$/, '') : null;
});
const tv = { width: 1920, height: 1080 };

// A TV waiting to be paired (in the script's own browser), and its
// code to show the confirm page with. Visiting it starts a pairing; nothing is confirmed.
await open('/tv');
const pairCode = await page
    .$eval('[aria-label^="Pairing code"]', (el) =>
        el.getAttribute('aria-label').split(' ').pop().replace('-', ''),
    )
    .catch(() => null);

// It fills its display: 1920 px wide gives each cell the width an 8-column screen has on a
// 1920 x 1080 TV, and rows as tall as that make a 1 x 1 tile square.
const galleryView = { width: 1920, height: 240 * 15 };
// Health first: the seeded checks' agents report no more, and go stale within minutes.
const galleryTiles = [
    'health-cards',
    'health-list',
    'health-single',
    'overview',
    'stat',
    'stat-sparkline',
    'stat-release',
    'gauge',
    'gauge-activity',
    'stat-activity',
    'chart-area',
    'chart-activity',
    'chart-split',
    'chart-bars',
    'list-issues',
    'list-queues',
    'list-calendar',
    'slo-availability',
    'slo-latency',
    'clock-world',
    'countdown',
    'countdown-calendar',
    'qr-wifi',
    'media-image',
    'message',
    'message-ticker',
    'display-default',
    'display-centered',
    'display-text-s',
    'display-title-l',
    'display-no-title',
    'display-no-period',
    'display-no-colors',
    'display-ok',
    'display-no-ok-border',
    'display-no-card',
];
const galleryLight = ['stat-sparkline', 'chart-area', 'list-issues'];
// A tile once its data is in: no loading text, a picture or a chart drawn where it has one.
const galleryTile = (id, theme) => ({
    path: gallery,
    viewport: galleryView,
    element: `[data-widget="${id}"]`,
    // Lists and cards hold still at their top, as at rest; a ticker has to run.
    reducedMotion: id !== 'message-ticker',
    before: async () => {
        await page.evaluate((mode) => {
            const screen = document.querySelector('.lj-screen');
            if (mode) {
                screen.dataset.theme = mode;
            }
            // Only the app: a dev toolbar in a corner would sit on the corner tile.
            const others = [
                ...document.documentElement.children,
                ...document.body.children,
            ].filter(
                (el) =>
                    el !== document.head &&
                    el !== document.body &&
                    !el.contains(screen),
            );
            for (const el of others) {
                el.style.visibility = 'hidden';
            }
        }, theme);
        await page.waitForFunction(
            (selector) => {
                const tile = document.querySelector(selector);
                const text = tile?.innerText ?? '';
                const media = [...(tile?.querySelectorAll('img') ?? [])];
                return (
                    tile &&
                    !text.includes('Loading...') &&
                    media.every((img) => img.complete && img.naturalWidth > 0)
                );
            },
            { timeout: 15_000 },
            `[data-widget="${id}"]`,
        );
        // A ticker held where its text starts at the tile's left edge.
        await page.evaluate((selector) => {
            const ticker = document.querySelector(`${selector} .lj-ticker`);
            const run = ticker?.getAnimations()[0];
            if (run) {
                const share =
                    ticker.parentElement.clientWidth / ticker.offsetWidth;
                run.pause();
                run.currentTime =
                    (share - 0.01) * run.effect.getTiming().duration;
            }
        }, `[data-widget="${id}"]`);
        await sleep(1200);
    },
});

const shots = {
    overview: { path: project },
    'overview-light': { path: project, theme: 'light' },
    issues: {
        path: `${project}/issues`,
        // Select a few issues so the bulk bar shows.
        before: async () => {
            const boxes = await page.$$('tbody input[type=checkbox]');
            for (const box of boxes.slice(0, 3)) {
                await box.click();
            }
            await sleep(400);
        },
    },
    issue: { path: issue, fullPage: true },
    'issue-notes': {
        path: issue,
        before: () => click('#tab-notes'),
        element: '[role=tablist]:has(#tab-notes)',
        parent: true,
    },
    trace: { path: trace },
    routes: { path: `${project}/requests`, fullPage: true },
    queries: { path: `${project}/database` },
    jobs: { path: `${project}/jobs`, fullPage: true },
    schedule: { path: `${project}/schedule` },
    commands: { path: `${project}/commands` },
    logs: { path: `${project}/logs` },
    // A month, so the deploys before the bad release and its rollback show too.
    releases: { path: `${project}/releases?period=30d` },
    health: {
        path: `${project}/health`,
        // The check that has had an outage, else the first.
        before: async () => {
            const links = await page.$$('.lj-row-link');
            const names = await Promise.all(
                links.map((l) => l.evaluate((el) => el.textContent)),
            );
            const i = names.findIndex((n) => n?.includes('payments'));
            await links[Math.max(i, 0)]?.click();
            await sleep(1000);
        },
        fullPage: true,
        maxHeight: 1700,
    },
    'settings-agents': { path: `${project}/settings#agents` },
    'settings-alerts': { path: `${project}/settings#alerts`, fullPage: true },
    'settings-retention': {
        path: `${project}/settings#retention`,
        fullPage: true,
    },
    'settings-triage': { path: `${project}/settings#triage` },
    'channel-wizard': {
        path: '/team/alerts',
        before: () => click('::-p-text(Add new channel)'),
        element: '[role=dialog]',
    },
    'team-alerts': {
        path: '/team/alerts',
        before: () => click('::-p-text(Custom alert topics)'),
        fullPage: true,
        maxHeight: 4600,
    },
    team: { path: '/team' },
    'admin-retention': { path: '/admin/retention', fullPage: true },
    screens: { path: '/present/screens' },
    'screen-designer': { path: screen },
    // A widget dragged from the palette over the grid, never dropped: the cells and its footprint.
    'screen-designer-drop': {
        path: screen,
        before: () =>
            page.evaluate(async () => {
                const dt = new DataTransfer();
                const stat = [
                    ...document.querySelectorAll('aside button[draggable]'),
                ].find((b) => b.textContent.startsWith('Gauge'));
                stat.dispatchEvent(
                    new DragEvent('dragstart', {
                        bubbles: true,
                        dataTransfer: dt,
                    }),
                );
                await new Promise((r) => setTimeout(r, 100));
                const grid = document.querySelector('.lj-grid');
                // Over the last cell no widget covers, so the footprint shows green.
                const free = [
                    ...grid.querySelectorAll(':scope > span.border-dashed'),
                ]
                    .map((cell) => cell.getBoundingClientRect())
                    .filter(
                        (r) =>
                            document.elementFromPoint(
                                r.left + r.width / 2,
                                r.top + r.height / 2,
                            ) === grid,
                    )
                    .pop();
                const at = free ?? grid.getBoundingClientRect();
                grid.dispatchEvent(
                    new DragEvent('dragover', {
                        bubbles: true,
                        cancelable: true,
                        dataTransfer: dt,
                        clientX: at.left + at.width / 2,
                        clientY: at.top + at.height / 2,
                    }),
                );
                await new Promise((r) => setTimeout(r, 100));
            }),
    },
    // The first widget's inspector on a value of its rotation other than the first.
    'screen-designer-rotation': {
        path: screen,
        before: () => click('aside [role=tab]:last-child'),
        element: 'aside:has([role=tablist])',
    },
    // The first widget's display settings, behind the gear in its corner.
    'screen-designer-display': {
        path: screen,
        before: () => click('[aria-label="Display settings"]'),
    },
    'screen-designer-incident': {
        path: screen,
        before: () => click('::-p-text(Show an incident)'),
    },
    'screen-kiosk': { path: kiosk, viewport: tv },
    // The kiosk follows the screen's own theme; show its light twin without saving it.
    'screen-kiosk-light': {
        path: kiosk,
        viewport: tv,
        before: () =>
            page.evaluate(() => {
                document.querySelector('.lj-screen').dataset.theme = 'light';
            }),
    },
    'tv-pairing': { path: '/tv', viewport: tv },
    'tv-pair-confirm': {
        path: pairCode ? `/pair/${pairCode}` : null,
        viewport: { width: 390, height: 844 },
    },
    ...Object.fromEntries(
        galleryTiles.map((id) => [`widget-${id}`, galleryTile(id)]),
    ),
    ...Object.fromEntries(
        galleryLight.map((id) => [
            `widget-${id}-light`,
            galleryTile(id, 'light'),
        ]),
    ),
    displays: { path: '/present/displays' },
    'command-palette': {
        path: project,
        before: async () => {
            await page.keyboard.down('Control');
            await page.keyboard.press('k');
            await page.keyboard.up('Control');
            await sleep(400);
            await page.keyboard.type('store');
            await sleep(1200);
        },
    },
};

const only = process.argv.slice(2);
await mkdir(OUT, { recursive: true });

const take = async (name, shot) => {
    if (shot.viewport) {
        await page.setViewport(shot.viewport);
    }
    await page.emulateMediaFeatures([
        {
            name: 'prefers-reduced-motion',
            value: shot.reducedMotion ? 'reduce' : 'no-preference',
        },
    ]);
    await open(shot.path, shot.theme);
    await shot.before?.();
    await mask();
    let target = shot.element ? await page.$(shot.element) : page;
    if (target && shot.parent) {
        target = await target.evaluateHandle((el) => el.parentElement);
    }
    if (!target) {
        console.warn(`skip ${name}: ${shot.element} not found`);
        return;
    }
    const path = new URL(`${name}.png`, OUT).pathname;
    // A long page grows the window, up to three screens, so the sidebar grows with it.
    if (shot.fullPage && !shot.element) {
        const height = await page.evaluate(
            () => document.documentElement.scrollHeight,
        );
        await page.setViewport({
            width: 1440,
            height: Math.min(Math.max(height, 900), shot.maxHeight ?? 2700),
        });
        await sleep(400);
    }
    await target.screenshot({ path, optimizeForSpeed: true });
    await page.setViewport({ width: 1440, height: 900 });
    console.log(`${name}.png`);
};

let failed = 0;
for (const [name, shot] of Object.entries(shots)) {
    if (only.length > 0 && !only.includes(name)) {
        continue;
    }
    if (!shot.path) {
        console.warn(`skip ${name}: nothing to show in ${PROJECT}`);
        continue;
    }
    // A live update can replace the page halfway; take it again once.
    try {
        await take(name, shot).catch(() => take(name, shot));
    } catch (error) {
        failed++;
        console.error(`fail ${name}: ${error.message}`);
        await page.setViewport({ width: 1440, height: 900 });
    }
}

await browser.close();
process.exitCode = failed > 0 ? 1 : 0;
