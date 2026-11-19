// Captures the stills the promo video is cut from: sharp (2x) pictures of a running
// Lumberjack, and the places in them the video points at (storage/app/promo/shots).
//
//   npm run docs:video            # capture, then render
//   node scripts/promo/capture.mjs
//
// LUMBERJACK_URL     where Lumberjack runs (https://lumberjack.test)
// DOCS_EMAIL         who signs in (the seeded maja@lumberjack)
// DOCS_PASSWORD      their password (password)
// DOCS_PROJECT       the project to show (the seeded Storefront)
// CHROME_PATH        a Chrome or Chromium to use instead of the one Puppeteer downloaded
//
// It only reads: it opens pages and dialogs but never saves or submits anything. Seed
// right before it (php artisan migrate:fresh --seed): the film is cut for the showcase
// data, and the seeded health checks go stale within minutes.
import { mkdir, writeFile } from 'node:fs/promises';
import puppeteer from 'puppeteer';

const BASE = (process.env.LUMBERJACK_URL ?? 'https://lumberjack.test').replace(
    /\/$/,
    '',
);
const EMAIL = process.env.DOCS_EMAIL ?? 'maja@lumberjack';
const PASSWORD = process.env.DOCS_PASSWORD ?? 'password';
const PROJECT = process.env.DOCS_PROJECT ?? 'Storefront';
const OUT = new URL('../../storage/app/promo/shots/', import.meta.url).pathname;
// The demo team under a name that fits a webshop; the film's address bar says the same.
const TEAM = 'Nordic Goods';
// The route whose slowest request the film opens: its trace has queries and calls on it.
const SLOW_ROUTE = 'checkout.store';
const BAD_RELEASE = 'v2.16.0';
const VIEW = { width: 1440, height: 900, deviceScaleFactor: 2 };

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
await mkdir(OUT, { recursive: true });

const browser = await puppeteer.launch({
    executablePath: process.env.CHROME_PATH || undefined,
    acceptInsecureCerts: true,
    args: ['--no-sandbox'],
    defaultViewport: VIEW,
});
const page = await browser.newPage();
page.on('pageerror', (error) => console.warn(`page error: ${error.message}`));
await page.emulateMediaFeatures([
    { name: 'prefers-reduced-motion', value: 'reduce' },
]);
await page.evaluateOnNewDocument(() => {
    localStorage.setItem('appearance', 'dark');
    // No dev toolbar and no toasts over the corners.
    const hide = () => {
        if (
            document.documentElement &&
            !document.getElementById('promo-hide')
        ) {
            const style = document.createElement('style');
            style.id = 'promo-hide';
            style.textContent =
                'monocle-button,[data-sonner-toaster]{display:none!important}';
            document.documentElement.append(style);
        }
    };
    hide();
    new MutationObserver(hide).observe(document, { childList: true });
});

const open = async (path) => {
    await page.goto(BASE + path, { waitUntil: 'networkidle0' });
    await page.waitForFunction(
        () => (document.body.innerText ?? '').trim().length > 50,
        { timeout: 15_000 },
    );
    await sleep(1200);
};
const brand = () =>
    page.evaluate((team) => {
        const walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
        );
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            if (node.textContent.includes('Local Team')) {
                node.textContent = node.textContent.replaceAll(
                    'Local Team',
                    team,
                );
                for (const el of node.parentElement
                    .closest('button')
                    ?.querySelectorAll('*') ?? []) {
                    if (
                        el.children.length === 0 &&
                        el.textContent.trim() === 'L'
                    ) {
                        el.textContent = team[0];
                    }
                }
            }
        }
    }, TEAM);

/**
 * Where the first element is that matches the selector and whose text matches the
 * pattern (a regular expression source), with its link if it has one.
 */
const find = (selector, pattern = '') =>
    page.evaluate(
        (selector, pattern) => {
            const el = [...document.querySelectorAll(selector)].find((e) =>
                new RegExp(pattern).test(e.textContent ?? ''),
            );
            if (!el) {
                return null;
            }
            const r = el.getBoundingClientRect();
            const link = el.matches('a') ? el : el.querySelector('a[href]');
            return {
                x: r.x,
                y: r.y,
                width: r.width,
                height: r.height,
                href: link ? new URL(link.href).pathname : null,
            };
        },
        selector,
        pattern,
    );
/** The bordered card around a label in the page's main column; `whole` for the card around its header. */
const card = (label, whole = false) =>
    page.evaluate(
        (label, whole) => {
            const leaf = [...document.querySelectorAll('body *')].find(
                (el) =>
                    el.children.length === 0 &&
                    el.textContent.trim().toLowerCase() ===
                        label.toLowerCase() &&
                    el.getBoundingClientRect().x > 240,
            );
            let box = leaf?.closest('[class*="border"]');
            if (whole) {
                box = box?.parentElement.closest('[class*="border"]');
            }
            const r = box?.getBoundingClientRect();
            return r
                ? { x: r.x, y: r.y, width: r.width, height: r.height }
                : null;
        },
        label,
        whole,
    );
const union = (...boxes) => {
    const all = boxes.filter(Boolean);
    if (all.length === 0) {
        return null;
    }
    const x = Math.min(...all.map((b) => b.x));
    const y = Math.min(...all.map((b) => b.y));
    return {
        x,
        y,
        width: Math.max(...all.map((b) => b.x + b.width)) - x,
        height: Math.max(...all.map((b) => b.y + b.height)) - y,
    };
};
/** Press the button of the first element that matches, or the element itself. */
const press = async (selector, pattern = '') => {
    await page.evaluate(
        (selector, pattern) => {
            const el = [...document.querySelectorAll(selector)].find((e) =>
                new RegExp(pattern).test(e.textContent ?? ''),
            );
            (el.matches('button, a')
                ? el
                : (el.querySelector('button, a') ?? el)
            ).click();
        },
        selector,
        pattern,
    );
    await sleep(1200);
};
/** Grow the window to the whole page, as the app scrolls inside itself. */
const tall = async (max) => {
    const height = await page.evaluate(() =>
        Math.max(
            ...[...document.querySelectorAll('*')].map(
                (el) => el.scrollHeight + el.getBoundingClientRect().top,
            ),
        ),
    );
    await page.setViewport({
        ...VIEW,
        height: Math.min(Math.max(Math.ceil(height), 900), max),
    });
    await sleep(700);
};

const rects = {};
/** Take the still, and keep the places in it the film points at. */
const shot = async (name, found = {}) => {
    await brand();
    await page.screenshot({ path: `${OUT}${name}.png` });
    const { width, height } = page.viewport();
    rects[name] = { width, height, ...found };
    for (const [key, value] of Object.entries(found)) {
        // The film can do without the line of code; it is cut around everything else.
        if (value === null && key !== 'line') {
            throw new Error(
                `${name}: found no ${key} on the page. Is the showcase seeded, or did the page change?`,
            );
        }
    }
    await page.setViewport(VIEW);
    console.log(`${name}.png`);
};

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
await open('/projects');
const project = (await find('a[href*="/projects/"]', PROJECT))?.href;
if (!project) {
    throw new Error(`No project named ${PROJECT} on /projects.`);
}

// Health first: the seeded checks' agents report no more, and go stale within minutes.
await open(`${project}/health`);
await shot('health');
const check = await find('.lj-row-link', 'Payments');
if (!check) {
    throw new Error('No health check named Payments: is the showcase seeded?');
}
await press('.lj-row-link', 'Payments');
await shot('health-check');

await open(project);
await tall(1900);
await shot('overview', {
    cards: union(await card('Requests'), await card('Open issues')),
    hosts: await card('Hosts'),
    charts: union(
        await card('Requests over time'),
        await card('Exceptions over time'),
    ),
});

// An issue with a stack trace, and the line it broke on.
await open(`${project}/issues`);
const issue = await find('tbody tr', '\\.php:\\d+');
if (!issue?.href) {
    throw new Error('No issue with a stack trace: is the showcase seeded?');
}
await shot('issues', { row: issue });
await open(issue.href);
await tall(1500);
const line = await page.evaluate(() => {
    const frame = document
        .querySelector('[role=tablist]:has(#tab-notes)')
        ?.parentElement.querySelector('ol li');
    // The line that threw is the one with a mark before its number.
    const row = [...(frame?.querySelectorAll('pre > div') ?? [])].find((el) =>
        el.querySelector('[aria-hidden]')?.textContent.trim(),
    );
    if (!row) {
        return null;
    }
    const r = row.getBoundingClientRect();
    const [file, number] = [
        ...frame.querySelectorAll(':scope > div span span'),
    ].map((el) => el.textContent);
    return {
        x: r.x,
        y: r.y,
        width: r.width,
        height: r.height,
        label: `${file.split('/').pop()}${number}`,
    };
});
await shot('issue', {
    stats: union(await card('Occurrences'), await card('Releases')),
    chart: await card('Occurrences per hour, 24 h'),
    stack: await page.evaluate(() => {
        const r = document
            .querySelector('[role=tablist]:has(#tab-notes)')
            ?.parentElement.getBoundingClientRect();
        return r ? { x: r.x, y: r.y, width: r.width, height: r.height } : null;
    }),
    line,
});

// A slow route, the slowest of its requests, and that request's trace: one path, as clicked.
await open(`${project}/requests`);
const slow = await find('tbody tr', SLOW_ROUTE);
if (!slow) {
    throw new Error(`No route named ${SLOW_ROUTE}: is the showcase seeded?`);
}
await shot('requests', { row: slow });
// A fresh page: the renamed team in the last still is not what the app rendered.
await open(`${project}/requests`);
await press('tbody tr', SLOW_ROUTE);
await page.waitForSelector('[role=dialog] a[href*="/traces/"]', {
    timeout: 10_000,
});
await sleep(800);
// The slowest of them that has its queries and calls kept: the SDK keeps those for a share of requests.
const slowest = await page.evaluate(() => {
    const rows = [
        ...document.querySelectorAll(
            '[role=dialog] li:has(a[href*="/traces/"])',
        ),
    ];
    const index = rows.findIndex(
        (row) =>
            /[1-9]\d* quer/.test(row.textContent) &&
            row.getBoundingClientRect().bottom < 860,
    );
    if (index === -1) {
        return null;
    }
    const r = rows[index].getBoundingClientRect();
    return {
        x: r.x,
        y: r.y,
        width: r.width,
        height: r.height,
        href: new URL(rows[index].querySelector('a').href).pathname,
        label: index === 0 ? 'Slowest request' : 'Slow request',
    };
});
await shot('route', { first: slowest });
await open(rects.route.first.href);
await shot('trace', { timeline: await card('Timeline', true) });

await open(`${project}/jobs`);
await shot('jobs');
await open(`${project}/schedule`);
await shot('schedule');
// A month, so the deploys before the bad release and its rollback show too.
await open(`${project}/releases?period=30d`);
await shot('releases', {
    row: await find('tbody tr', BAD_RELEASE.replaceAll('.', '\\.')),
});

await open('/team/alerts');
await press('button', '^Add new channel$');
await shot('alerts-channel', { dialog: await find('[role=dialog]') });

// The first status screen: in the designer, and as a TV shows it.
await open('/present/screens');
const editor = (await find('a[href$="/edit"]'))?.href;
if (!editor) {
    throw new Error('No status screen: is the showcase seeded?');
}
await open(editor);
await shot('designer', { grid: await find('.lj-grid') });
await page.setViewport({ width: 1920, height: 1080, deviceScaleFactor: 2 });
await open(editor.replace(/\/edit$/, ''));
await sleep(1500);
await shot('kiosk');

await writeFile(`${OUT}rects.json`, JSON.stringify(rects, null, 1));
await browser.close();
