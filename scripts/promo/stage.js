// The promo video as one page: every moving thing is a tween on a shared clock, and
// renderAt(seconds) draws the frame for that moment. Nothing runs on real time, so the
// renderer can take as long as it likes per frame and the video is still smooth.
//
// RECTS (the size of each still and the places in it to point at) comes from
// capture.mjs. A place it did not find is null, and its highlight is then left out.
/* global RECTS */
const $ = (html) => {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
};
const stage = document.getElementById('stage');

// ---- Engine -------------------------------------------------------------------
const ease = {
    linear: (p) => p,
    out: (p) => 1 - (1 - p) ** 4,
    expo: (p) => (p === 1 ? 1 : 1 - 2 ** (-10 * p)),
    inOut: (p) => (p < 0.5 ? 4 * p * p * p : 1 - (-2 * p + 2) ** 3 / 2),
    back: (p) => 1 + 2.2 * (p - 1) ** 3 + 1.2 * (p - 1) ** 2,
};
const tweens = [];
/** Move `el` from `from` (or wherever it is) to `to`, starting at t0 and taking d seconds. */
const tw = (el, t0, d, from, to, e = 'inOut') =>
    tweens.push({ el, t0, d, from, to, e: ease[e], n: tweens.length });
const set = (el, t, to) => tw(el, t, 0.0001, null, to, 'linear');
const draw = (el, s) => {
    const o = s.o ?? 1;
    el.style.opacity = o;
    el.style.visibility = o <= 0.001 ? 'hidden' : '';
    const scale = s.s ?? 1;
    el.style.transform =
        `translate3d(${s.x ?? 0}px,${s.y ?? 0}px,0) rotateX(${s.rx ?? 0}deg) rotateY(${s.ry ?? 0}deg) ` +
        `rotate(${s.rz ?? 0}deg) scale(${scale * (s.sx ?? 1)},${scale})`;
    if ('blur' in s)
        el.style.filter = s.blur > 0.05 ? `blur(${s.blur}px)` : 'none';
    if ('hue' in s) el.style.filter = `hue-rotate(${s.hue}deg)`;
    if ('dash' in s) el.style.strokeDashoffset = 1 - s.dash;
    if ('clip' in s)
        el.style.clipPath = `inset(-20% ${100 - s.clip * 100}% -20% 0)`;
    if ('z' in s) el.style.zIndex = Math.round(s.z);
};
let sorted = null;
window.renderAt = (t) => {
    sorted ??= [...tweens].sort((a, b) => a.t0 - b.t0 || a.n - b.n);
    const states = new Map();
    for (const k of sorted) {
        let s = states.get(k.el);
        if (!s) {
            states.set(k.el, (s = { ...k.from }));
        }
        if (t < k.t0) {
            continue;
        }
        const p = k.e(Math.min(1, (t - k.t0) / k.d));
        for (const key of Object.keys(k.to)) {
            const a =
                k.from?.[key] ??
                s[key] ??
                (key === 's' || key === 'sx' || key === 'o' ? 1 : 0);
            s[key] = a + (k.to[key] - a) * p;
        }
    }
    for (const [el, s] of states) {
        draw(el, s);
    }
};

// ---- Background ----------------------------------------------------------------
const rings = $(
    `<svg id="rings" viewBox="-1000 -1000 2000 2000">${Array.from(
        { length: 13 },
        (_, i) => {
            const r = 90 + i * 74 + (i % 3) * 9;
            return `<circle cx="${i * 4}" cy="${i * 3}" r="${r}" />`;
        },
    ).join('')}</svg>`,
);
const glow = $('<div id="glow"></div>');
const glow2 = $('<div id="glow2"></div>');
stage.append($('<div id="bg"></div>'), rings, glow, glow2);
const world = $('<div id="world"></div>');
stage.append(world);

// ---- The browser window ---------------------------------------------------------
const frame = $(`<div id="frame"><div class="bar"><i></i><i></i><i></i>
    <span class="url"><b></b>lumberjack.nordicgoods.com</span></div><div id="vp"><div id="cam"></div></div></div>`);
world.append(frame);
const cam = frame.querySelector('#cam');
const pages = {};
for (const [name, r] of Object.entries(RECTS)) {
    if (!r.width || name === 'kiosk') {
        continue;
    }
    const scale = 1440 / r.width;
    const page = $(
        `<div class="page" style="height:${r.height * scale}px"><img src="shots/${name}.png"></div>`,
    );
    pages[name] = page;
    cam.append(page);
    tw(page, 0, 0.001, { o: 0, z: 0 }, { o: 0 });
}
const cursor = $(
    `<svg id="cursor" viewBox="0 0 24 24"><path d="M4 3l15.5 8.2-6.6 1.9-2.6 6.4z" fill="#fff" stroke="#161A1E" stroke-width="1.4" stroke-linejoin="round"/></svg>`,
);
const ripple = $('<div id="ripple"></div>');
cam.append(ripple, cursor);
tw(cursor, 0, 0.001, { o: 0, x: 900, y: 700, z: 900 }, { o: 0 });
tw(ripple, 0, 0.001, { o: 0, s: 0.2, z: 899 }, { o: 0 });
tw(cam, 0, 0.001, { x: 0, y: 0, s: 1 }, { s: 1 });

let shown = null;
let order = 1;
/** Cut to another page of the app, with a short dissolve. */
const show = (name, t, d = 0.45) => {
    const next = pages[name];
    const z = ++order;
    set(next, t, { z });
    tw(next, t, d, { o: 0 }, { o: 1 }, 'out');
    if (shown && shown !== next) {
        set(shown, t + d, { o: 0 });
    }
    shown = next;
    current = name;
};
let current = null;
/** Point the camera inside the window at a spot of the page, at a zoom. */
const camTo = (t, d, { cx = 720, cy = 450, s = 1 }, e = 'inOut') => {
    const height = RECTS[current].height;
    const x = Math.min(0, Math.max(1440 - 1440 * s, 720 - cx * s));
    const y = Math.min(0, Math.max(900 - height * s, 450 - cy * s));
    tw(cam, t, d, null, { x, y, s }, e);
};
const mid = (r) => ({ cx: r.x + r.width / 2, cy: r.y + r.height / 2 });
/** Light up a part of the page and dim the rest, with a tag on it. */
const hl = (name, r, label, tIn, tOut, pad = 8) => {
    if (!r) {
        return;
    }
    const box =
        $(`<div class="hl" style="left:${r.x - pad}px;top:${r.y - pad}px;width:${r.width + pad * 2}px;height:${r.height + pad * 2}px">
        ${label ? `<span>${label}</span>` : ''}</div>`);
    pages[name].append(box);
    tw(box, tIn, 0.5, { o: 0, s: 1.05 }, { o: 1, s: 1 }, 'out');
    tw(box, tOut, 0.35, null, { o: 0 }, 'out');
};
const moveCursor = (t, d, x, y) => {
    tw(cursor, t - 0.3, 0.3, null, { o: 1 }, 'out');
    tw(cursor, t, d, null, { x, y }, 'inOut');
};
const click = (t, x, y) => {
    tw(cursor, t, 0.1, null, { s: 0.8 }, 'out');
    tw(cursor, t + 0.1, 0.15, null, { s: 1 }, 'out');
    set(ripple, t, { x: x - 30, y: y - 30, s: 0.2, o: 1 });
    tw(ripple, t, 0.55, null, { s: 1.6, o: 0 }, 'out');
    tw(cursor, t + 0.45, 0.3, null, { o: 0 }, 'out');
};

// Where the window sits: beside the text on either side, turned toward it.
const SIDES = {
    right: { x: 1262 - 720, y: 540 - 472, s: 0.78, ry: -9, rx: 2.5 },
    left: { x: 658 - 720, y: 540 - 472, s: 0.78, ry: 9, rx: 2.5 },
};
tw(frame, 0, 0.001, { ...SIDES.right, o: 0 }, { o: 0 });

// ---- Text ------------------------------------------------------------------------
const words = (text) =>
    text
        .split(' ')
        .map((w) =>
            w.startsWith('*')
                ? `<span class="w a">${w.replaceAll('*', '')}</span>`
                : `<span class="w">${w}</span>`,
        )
        .join(' ');
/** A kicker, a title and one or more lines under it that take turns. */
const headline = (side, kicker, title, subs, tIn, tOut) => {
    const el = $(
        `<div class="tx ${side}"><div class="kicker"><i></i>${kicker}</div><h1>${words(title)}</h1><div class="subs"></div></div>`,
    );
    stage.append(el);
    tw(el, tIn, 0.001, { o: 0, x: 0 }, { o: 1 });
    tw(
        el.querySelector('.kicker'),
        tIn,
        0.6,
        { o: 0, x: -24 },
        { o: 1, x: 0 },
        'expo',
    );
    el.querySelectorAll('.w').forEach((w, i) => {
        tw(
            w,
            tIn + 0.12 + i * 0.07,
            0.8,
            { o: 0, y: 46, blur: 10 },
            { o: 1, y: 0, blur: 0 },
            'expo',
        );
    });
    subs.forEach(([text, at], i) => {
        const sub = $(`<p>${text}</p>`);
        el.querySelector('.subs').append(sub);
        const start = at ?? tIn + 0.55;
        tw(sub, start, 0.6, { o: 0, y: 18 }, { o: 1, y: 0 }, 'expo');
        const next = subs[i + 1]?.[1];
        if (next) {
            tw(sub, next - 0.3, 0.3, null, { o: 0, y: -12 }, 'out');
        }
    });
    tw(el, tOut, 0.4, null, { o: 0, x: side === 'left' ? -50 : 50 }, 'out');
};

// ---- Transitions -------------------------------------------------------------------
const sweepEl = $('<div id="sweep"><div></div></div>');
stage.append(sweepEl);
tw(sweepEl, 0, 0.001, { x: -4200 }, { x: -4200 });
/** A dark sheet with glowing edges crosses the picture; it covers everything at `t`. */
const sweep = (t) => {
    set(sweepEl, t - 0.6, { x: -4100 });
    tw(sweepEl, t - 0.6, 1.2, null, { x: 2300 }, 'inOut');
};

// Chapter marks along the bottom.
const CHAPTERS = [
    ['Overview', 8.6, 18],
    ['Issues', 18, 31],
    ['Performance', 31, 41],
    ['Background work', 41, 48.5],
    ['Releases', 48.5, 55.5],
    ['Health & alerts', 55.5, 66],
    ['Status screens', 66, 76],
];
const marks = $('<div id="marks"></div>');
stage.append(marks);
tw(marks, 8.9, 0.6, { o: 0, y: 20 }, { o: 1, y: 0 }, 'expo');
tw(marks, 75.6, 0.4, null, { o: 0 }, 'out');
for (const [name, from, to] of CHAPTERS) {
    const mark = $(
        `<div class="mark"><span>${name}</span><div><b></b></div></div>`,
    );
    marks.append(mark);
    tw(
        mark.querySelector('b'),
        from,
        to - from,
        { sx: 0 },
        { sx: 1 },
        'linear',
    );
    tw(mark.querySelector('span'), from, 0.4, { o: 0.35 }, { o: 1 }, 'out');
    tw(mark.querySelector('span'), to, 0.4, null, { o: 0.35 }, 'out');
}

// ---- Background motion over the whole film --------------------------------------------
tw(rings, 0, 90, { s: 0.9, rz: 0, o: 1 }, { s: 1.35, rz: 40 }, 'linear');
tw(glow, 0, 0.001, { x: 560, y: 140, hue: 0, o: 1 }, { o: 1 });
tw(glow2, 0, 0.001, { x: 1100, y: 600, o: 0.0 }, { o: 0 });
const mood = (t, x, y, hue) => tw(glow, t, 1.6, null, { x, y, hue }, 'inOut');

// ---- 0.0  Intro -------------------------------------------------------------------------
const intro =
    $(`<div class="center" id="intro"><svg class="logo" viewBox="3 3 26 26">
    <circle pathLength="1" cx="16" cy="16" r="11"/><circle pathLength="1" cx="16.6" cy="16.6" r="7"/>
    <circle pathLength="1" cx="17.1" cy="17.1" r="3"/><path pathLength="1" d="M15 5l1.2 5.6"/></svg>
    <div class="brand">${'Lumberjack'
        .split('')
        .map((c) => `<span class="w">${c}</span>`)
        .join('')}</div>
    <div class="tag">${words('Every layer of your app, *ring* *by* *ring*')}</div></div>`);
stage.append(intro);
tw(intro, 0, 0.001, { o: 1, s: 1 }, { o: 1 });
intro
    .querySelectorAll('.logo *')
    .forEach((p, i) =>
        tw(p, 0.3 + i * 0.22, 1.1, { dash: 0 }, { dash: 1 }, 'inOut'),
    );
tw(
    intro.querySelector('.logo'),
    0.2,
    1.8,
    { s: 0.6, rz: -90, o: 0 },
    { s: 1, rz: 0, o: 1 },
    'expo',
);
intro
    .querySelectorAll('.brand .w')
    .forEach((w, i) =>
        tw(
            w,
            1.1 + i * 0.045,
            0.9,
            { o: 0, y: 70, blur: 14 },
            { o: 1, y: 0, blur: 0 },
            'expo',
        ),
    );
intro
    .querySelectorAll('.tag .w')
    .forEach((w, i) =>
        tw(w, 2.0 + i * 0.07, 0.7, { o: 0, y: 24 }, { o: 1, y: 0 }, 'expo'),
    );
tw(intro, 4.0, 0.6, null, { o: 0, s: 1.25 }, 'inOut');

// ---- 4.6  Hook ----------------------------------------------------------------------------
const hook =
    $(`<div id="hook"><div><span>Errors.</span></div><div><span>Performance.</span></div><div><span>Logs.</span></div>
    <div class="a"><span>On your own server.</span></div></div>`);
stage.append(hook);
tw(hook, 4.5, 0.001, { o: 0, x: 0 }, { o: 1 });
hook.querySelectorAll('span').forEach((line, i) => {
    const at = 4.6 + i * 0.62 + (i === 3 ? 0.25 : 0);
    tw(line, at, 0.75, { y: 190, o: 0 }, { y: 0, o: 1 }, 'expo');
});
mood(4.4, 1000, 300, -8);
sweep(8.6);
set(hook, 8.6, { o: 0 });

// ---- 8.6  Overview ---------------------------------------------------------------------------
const O = RECTS.overview;
set(frame, 8.6, { o: 1, ...SIDES.right, ry: -22, s: 0.7 });
tw(frame, 8.6, 2.2, null, { ry: -9, s: 0.78 }, 'expo');
show('overview', 8.6, 0.001);
headline(
    'left',
    'Overview',
    'Every app, *at* *a* *glance*',
    [
        [
            'Requests, error rate, p95, failed jobs, open issues and health checks.',
        ],
        ['Hosts, traffic and errors over the period.', 14.3],
    ],
    9.0,
    17.7,
);
mood(8.8, 300, 520, 0);
camTo(11.2, 1.3, { cx: 560, cy: O.cards.y + 110, s: 1.7 });
camTo(12.6, 1.6, { cx: 1140, cy: O.cards.y + 110, s: 1.7 });
camTo(14.3, 1.5, { cx: 836, cy: O.hosts.y + 160, s: 1.25 });
camTo(16.0, 1.6, { cx: 836, cy: O.charts.y + 260, s: 1.25 });

/** Turn the window to the other side while the page behind the glass changes. */
const swing = (t, side, page) => {
    tw(frame, t, 1.15, null, SIDES[side], 'inOut');
    tw(frame, t, 0.55, null, { s: 0.68 }, 'inOut');
    tw(frame, t + 0.55, 0.6, null, { s: 0.78 }, 'inOut');
    show(page, t + 0.3, 0.5);
    tw(cam, t + 0.3, 0.001, null, { x: 0, y: 0, s: 1 }, 'linear');
};

// ---- 18.0  Issues -------------------------------------------------------------------------------
const I = RECTS.issues;
const D = RECTS.issue;
swing(18.0, 'left', 'issues');
mood(18.0, 1250, 180, -28);
headline(
    'right',
    'Issues',
    'Know when production *breaks*',
    [
        ['Exceptions, browser errors and error logs become issues.'],
        ['How often it happens, and who it affects.', 23.2],
        ['With the stack trace, down to the line in your own code.', 26.4],
    ],
    18.6,
    30.7,
);
camTo(20.2, 1.2, { cx: 760, cy: I.row.y + 150, s: 1.3 });
hl('issues', I.row, 'New · Escalating', 20.8, 22.7);
moveCursor(21.4, 0.9, I.row.x + 300, I.row.y + 34);
click(22.4, I.row.x + 300, I.row.y + 34);
show('issue', 22.7, 0.5);
tw(cam, 22.7, 0.9, null, { x: 0, y: 0, s: 1 }, 'inOut');
camTo(23.7, 1.2, { cx: 836, cy: D.stats.y + 100, s: 1.22 });
hl(
    'issue',
    {
        x: D.stats.x,
        y: D.stats.y,
        width: 1152,
        height: D.chart.y + D.chart.height - D.stats.y,
    },
    '',
    24.3,
    26.2,
);
camTo(26.4, 1.3, {
    cx: D.stack.x + D.stack.width / 2,
    cy: D.stack.y + 120,
    s: 1.7,
});
hl('issue', D.line, D.line?.label, 27.6, 30.6, 3);

// ---- 31.0  Performance ------------------------------------------------------------------------------
const R = RECTS.requests;
swing(31.0, 'right', 'requests');
mood(31.0, 260, 240, 165);
headline(
    'left',
    'Performance',
    'Find out why a page is *slow*',
    [
        ['Response times, error rate and Apdex for every route.'],
        ['Open a route for its slowest requests.', 35.6],
        ['Down to a single trace: every query, call and mail.', 38.3],
    ],
    31.6,
    40.7,
);
const P = RECTS.route;
const T = RECTS.trace.timeline;
camTo(33.0, 1.1, { cx: 846, cy: R.row.y + 40, s: 1.25 });
hl('requests', R.row, 'Slow checkout', 33.6, 35.3, 4);
moveCursor(34.3, 0.8, R.row.x + 120, R.row.y + 24);
click(35.2, R.row.x + 120, R.row.y + 24);
show('route', 35.4, 0.45);
tw(cam, 35.4, 0.7, null, { x: 0, y: 0, s: 1 }, 'inOut');
camTo(36.1, 0.9, { cx: 860, cy: Math.max(330, P.first.y + 40), s: 1.3 });
hl('route', P.first, P.first.label, 36.7, 38.0, 2);
moveCursor(37.0, 0.7, P.first.x + 200, P.first.y + 30);
click(37.8, P.first.x + 200, P.first.y + 30);
show('trace', 38.0, 0.45);
tw(cam, 38.0, 0.7, null, { x: 0, y: 0, s: 1 }, 'inOut');
camTo(38.8, 1.1, {
    cx: T.x + T.width / 2,
    cy: T.y + T.height / 2 - 30,
    s: 1.24,
});
camTo(
    39.9,
    1.2,
    { cx: T.x + T.width / 2, cy: T.y + T.height / 2, s: 1.28 },
    'linear',
);

// ---- 41.0  Background work ------------------------------------------------------------------------------
swing(41.0, 'left', 'jobs');
mood(41.0, 1200, 560, 20);
headline(
    'right',
    'Background work',
    'Watch the work nobody *sees*',
    [
        ['Which jobs failed, and which queues back up.'],
        ['Scheduled tasks: which ran, failed or never ran.', 44.8],
    ],
    41.6,
    48.2,
);
camTo(42.4, 2.4, { cx: 836, cy: 400, s: 1.16 }, 'out');
show('schedule', 44.8, 0.6);
tw(cam, 44.8, 0.001, null, { x: -160, y: -40, s: 1.1 }, 'linear');
camTo(44.8, 3.6, { cx: 836, cy: 380, s: 1.24 }, 'out');

// ---- 48.5  Releases -----------------------------------------------------------------------------------------
const L = RECTS.releases;
swing(48.5, 'right', 'releases');
mood(48.5, 300, 200, -18);
headline(
    'left',
    'Releases',
    'Check every *release*',
    [['See which deploy brought new errors or slowdowns.']],
    49.1,
    55.2,
);
camTo(50.3, 1.3, { cx: 836, cy: L.row.y + 80, s: 1.28 });
hl('releases', L.row, 'Bad release · rolled back', 51.3, 55.2, 4);

// ---- 55.5  Health and alerts -----------------------------------------------------------------------------------
const A = RECTS['alerts-channel'].dialog;
swing(55.5, 'left', 'health');
mood(55.5, 1280, 220, 80);
headline(
    'right',
    'Health & alerts',
    'Know when a site is *down*',
    [
        ['HTTP, SSL, TCP, DNS and heartbeat checks.'],
        ['Every check keeps its outages and uptime.', 58.8],
        [
            'Alerts by email, Slack, Teams, Discord, Telegram, ntfy, Pushover or webhook.',
            62.0,
        ],
    ],
    56.1,
    65.6,
);
camTo(56.8, 2.0, { cx: 836, cy: 470, s: 1.18 }, 'out');
show('health-check', 58.8, 0.5);
tw(cam, 58.8, 0.9, null, { x: 0, y: 0, s: 1 }, 'inOut');
camTo(59.7, 2.3, { cx: 720, cy: 420, s: 1.12 }, 'out');
show('alerts-channel', 62.0, 0.5);
tw(cam, 62.0, 0.8, null, { x: 0, y: 0, s: 1 }, 'inOut');
camTo(62.8, 1.3, { ...mid(A), s: 1.55 });

// ---- 66.0  Status screens ------------------------------------------------------------------------------------------
const G = RECTS.designer.grid;
sweep(66.0);
set(frame, 66.0, { ...SIDES.right });
show('designer', 66.0, 0.001);
set(cam, 66.0, { x: 0, y: 0, s: 1 });
mood(65.6, 320, 480, 0);
headline(
    'left',
    'Status screens',
    'Put it on the *wall*',
    [
        ['Design a status screen from widgets.'],
        ['Pair any TV with a code. It updates live.', 70.6],
    ],
    66.3,
    75.7,
);
camTo(67.6, 1.3, { ...mid(G), s: 1.3 });
hl('designer', G, 'Drag widgets onto the grid', 68.3, 70.3, 6);
const tv = $(
    `<div id="tv"><div class="screen"><img src="shots/kiosk.png"></div><div class="neck"></div><div class="foot"></div><div class="spill"></div></div>`,
);
world.append(tv);
tw(
    tv,
    70.4,
    1.3,
    { o: 0, x: 1262 - 540, y: 230 + 140, s: 0.86, ry: -26, rx: 6 },
    { o: 1, y: 230, s: 1, ry: -9, rx: 2.5 },
    'expo',
);
tw(frame, 70.3, 0.8, null, { o: 0, s: 0.66, x: SIDES.right.x - 160 }, 'inOut');
tw(tv, 71.7, 4.3, null, { ry: -5, s: 1.04 }, 'linear');
sweep(76.0);
set(tv, 76.0, { o: 0 });

// ---- 76.0  And more -----------------------------------------------------------------------------------------------------
const MORE = [
    'Search your logs',
    'Live tail',
    'Source maps',
    'Web Vitals',
    'N+1 hints',
    'Weekly summary',
    'OpenTelemetry',
    'MCP for AI agents',
    'Grafana',
    'Maintenance windows',
    'Teams & roles',
    'Ctrl+K anywhere',
];
const more =
    $(`<div class="center" id="more"><div class="kicker"><i></i>And there is more</div>
    <h1>${words('Everything in *one* place')}</h1><div class="pills">${MORE.map((m) => `<span><i></i>${m}</span>`).join('')}</div></div>`);
stage.append(more);
tw(more, 76.0, 0.001, { o: 0, s: 1 }, { o: 1 });
mood(75.8, 560, 160, 0);
tw(
    more.querySelector('.kicker'),
    76.2,
    0.6,
    { o: 0, y: 16 },
    { o: 1, y: 0 },
    'expo',
);
more.querySelectorAll('h1 .w').forEach((w, i) =>
    tw(
        w,
        76.3 + i * 0.07,
        0.8,
        { o: 0, y: 46, blur: 10 },
        { o: 1, y: 0, blur: 0 },
        'expo',
    ),
);
more.querySelectorAll('.pills span').forEach((p, i) =>
    tw(
        p,
        77.0 + i * 0.09,
        0.7,
        { o: 0, y: 34, s: 0.8 },
        { o: 1, y: 0, s: 1 },
        'back',
    ),
);
tw(more, 81.6, 0.6, null, { o: 0, s: 1.12 }, 'inOut');

// ---- 82.0  Outro --------------------------------------------------------------------------------------------------------------
const outro =
    $(`<div class="center" id="outro"><svg class="logo" viewBox="3 3 26 26">
    <circle pathLength="1" cx="16" cy="16" r="11"/><circle pathLength="1" cx="16.6" cy="16.6" r="7"/>
    <circle pathLength="1" cx="17.1" cy="17.1" r="3"/><path pathLength="1" d="M15 5l1.2 5.6"/></svg>
    <h1>${words('Run it on your *own* *server*')}</h1>
    <div class="code"><span>curl -fsSL https://exeque.github.io/lumberjack/install.sh | sh</span></div>
    <div class="url2">exeque.github.io/lumberjack</div></div>`);
stage.append(outro);
tw(outro, 82.0, 0.001, { o: 0 }, { o: 1 });
outro
    .querySelectorAll('.logo *')
    .forEach((p, i) =>
        tw(p, 82.1 + i * 0.15, 0.9, { dash: 0 }, { dash: 1 }, 'inOut'),
    );
outro
    .querySelectorAll('h1 .w')
    .forEach((w, i) =>
        tw(
            w,
            82.6 + i * 0.07,
            0.8,
            { o: 0, y: 46, blur: 10 },
            { o: 1, y: 0, blur: 0 },
            'expo',
        ),
    );
tw(
    outro.querySelector('.code'),
    83.5,
    0.7,
    { o: 0, y: 24, s: 0.96 },
    { o: 1, y: 0, s: 1 },
    'expo',
);
tw(
    outro.querySelector('.code span'),
    84.0,
    1.7,
    { clip: 0 },
    { clip: 1 },
    'linear',
);
tw(
    outro.querySelector('.url2'),
    85.9,
    0.7,
    { o: 0, y: 16 },
    { o: 1, y: 0 },
    'expo',
);
tw(outro, 89.2, 0.7, null, { o: 0 }, 'out');

window.DURATION = 90;

// Opened by hand with #play (or #play=31 to start there), the film runs in the browser.
if (location.hash.startsWith('#play')) {
    const from = Number(location.hash.split('=')[1] ?? 0);
    const started = performance.now();
    const tick = () => {
        window.renderAt(
            (from + (performance.now() - started) / 1000) % window.DURATION,
        );
        requestAnimationFrame(tick);
    };
    tick();
}
