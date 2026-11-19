import type { ReactNode } from 'react';
import { camera, sec, useTime } from '../anim';
import { C, FONT, MONO } from '../theme';
import {
    AppShell,
    Badge,
    Camera,
    Card,
    CardHead,
    colStyle,
    Cursor,
    Line,
    PageHeader,
    Spot,
    THead,
    type Col,
} from '../ui';
import { Feature, PageSwap } from './feature';

const DURATION = 12;
export const PERFORMANCE = sec(DURATION);

const OPEN_ROUTE = 2.8;
const OPEN_TRACE = 5.2;

const ROUTES = [
    {
        method: 'POST',
        path: '/checkout/payment',
        name: 'checkout.payment',
        domain: 'shop.example.com',
        requests: '1,284',
        p50: '1.21 s',
        p95: '5.32 s',
        p99: '5.40 s',
        error: '18.4%',
        total: '1 h 2 min',
        apdex: '0.41',
    },
    {
        method: 'GET',
        path: '/admin/reports/sales',
        name: 'admin.reports.sales',
        domain: 'admin.shop.example.com',
        requests: '60',
        p50: '4.40 s',
        p95: '4.90 s',
        p99: '5.02 s',
        error: '0%',
        total: '7 min 9 s',
        apdex: '0.00',
    },
    {
        method: 'GET',
        path: '/sitemap.xml',
        name: 'sitemap',
        domain: 'shop.example.com',
        requests: '162',
        p50: '1.41 s',
        p95: '2.44 s',
        p99: '2.44 s',
        error: '0%',
        total: '7 min 18 s',
        apdex: '0.42',
    },
    {
        method: 'GET',
        path: '/search',
        name: 'search',
        domain: 'shop.example.com',
        requests: '3,164',
        p50: '388 ms',
        p95: '1.40 s',
        p99: '2.38 s',
        error: '0%',
        total: '29 min 14 s',
        apdex: '0.82',
    },
    {
        method: 'GET',
        path: '/account/orders',
        name: 'account.orders.index',
        domain: 'shop.example.com',
        requests: '826',
        p50: '303 ms',
        p95: '902 ms',
        p99: '1.69 s',
        error: '0%',
        total: '5 min 7 s',
        apdex: '0.89',
    },
    {
        method: 'POST',
        path: '/cart/items',
        name: 'cart.items.store',
        domain: 'shop.example.com',
        requests: '1,968',
        p50: '149 ms',
        p95: '565 ms',
        p99: '852 ms',
        error: '0%',
        total: '5 min 51 s',
        apdex: '0.97',
    },
    {
        method: 'GET',
        path: '/products/{slug}',
        name: 'products.show',
        domain: 'shop.example.com',
        requests: '10,399',
        p50: '124 ms',
        p95: '327 ms',
        p99: '870 ms',
        error: '0%',
        total: '27 min 14 s',
        apdex: '0.99',
    },
    {
        method: 'GET',
        path: '/cart',
        name: 'cart.show',
        domain: 'shop.example.com',
        requests: '2,763',
        p50: '108 ms',
        p95: '321 ms',
        p99: '1.67 s',
        error: '0%',
        total: '6 min 21 s',
        apdex: '0.98',
    },
];

const ROUTE_COLS: Col[] = [
    ['Route', 'flex'],
    ['Domain', 170],
    ['Requests', 74, 'right'],
    ['P50', 64, 'right'],
    ['P95', 64, 'right'],
    ['P99', 64, 'right'],
    ['Error rate', 80, 'right'],
    ['Apdex', 54, 'right'],
    ['Requests over time', 120],
];

const wave = (seed: number) =>
    Array.from(
        { length: 16 },
        (_, i) => 4 + Math.sin(i * 0.9 + seed) * 2 + ((i * seed) % 3),
    );

const apdexColor = (apdex: string) =>
    Number(apdex) < 0.7 ? C.err : Number(apdex) < 0.85 ? C.warn : C.ink;

const RouteList = () => (
    <AppShell
        active="Requests"
        crumbs={['Nordic Goods', 'Storefront', 'Requests']}
    >
        <PageHeader
            title="Requests"
            subtitle={
                <>
                    15 routes, 33,273 requests. Select a route for details.
                    <Badge>Estimated from a 5.00% sample</Badge>
                </>
            }
        />
        <Card>
            <THead cols={ROUTE_COLS} />
            {ROUTES.map((route, i) => (
                <div
                    key={route.path}
                    style={{
                        display: 'flex',
                        gap: 14,
                        alignItems: 'center',
                        height: 58,
                        padding: '0 18px',
                        borderTop: i ? `1px solid ${C.line}` : undefined,
                        fontFamily: MONO,
                        fontSize: 14,
                    }}
                >
                    <span
                        style={{
                            ...colStyle('flex'),
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                        }}
                    >
                        <span style={{ color: C.ink3 }}>{route.method}</span>{' '}
                        {route.path}{' '}
                        <span style={{ color: C.ink3 }}>{route.name}</span>
                    </span>
                    <span
                        style={{
                            ...colStyle(170),
                            color: C.ink2,
                            fontSize: 13,
                        }}
                    >
                        {route.domain}
                    </span>
                    <span style={{ ...colStyle(74), textAlign: 'right' }}>
                        {route.requests}
                    </span>
                    <span style={{ ...colStyle(64), textAlign: 'right' }}>
                        {route.p50}
                    </span>
                    <span style={{ ...colStyle(64), textAlign: 'right' }}>
                        {route.p95}
                    </span>
                    <span style={{ ...colStyle(64), textAlign: 'right' }}>
                        {route.p99}
                    </span>
                    <span
                        style={{
                            ...colStyle(80),
                            textAlign: 'right',
                            color: route.error === '0%' ? C.ink3 : C.ink,
                        }}
                    >
                        {route.error}
                    </span>
                    <span
                        style={{
                            ...colStyle(54),
                            textAlign: 'right',
                            color: apdexColor(route.apdex),
                        }}
                    >
                        {route.apdex}
                    </span>
                    <span style={colStyle(120)}>
                        <Line
                            values={wave(i + 1)}
                            at={0.4 + i * 0.05}
                            width={120}
                            height={28}
                            color={C.info}
                            fill={false}
                            strokeWidth={1.6}
                            duration={0.8}
                        />
                    </span>
                </div>
            ))}
        </Card>
    </AppShell>
);

const SLOWEST = [
    [
        '03:14:22 AM',
        'web-2',
        '26 queries',
        '5f50659c0a2701f5374c554533aa5c65',
        '5.31 s',
        '500',
    ],
    [
        '03:12:09 AM',
        'web-1',
        '26 queries',
        '8e1c4b0d93a7f2e6c5b1d0a9f8e7c6b5',
        '5.30 s',
        '500',
    ],
    [
        '03:09:41 AM',
        'web-3',
        '25 queries',
        '2b7a9f1e4c8d3b6a5f0e9d8c7b6a5f4e',
        '5.29 s',
        '500',
    ],
    [
        '03:07:58 AM',
        'web-2',
        '26 queries',
        'c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9',
        '5.28 s',
        '500',
    ],
];

const RouteModal = () => (
    <div style={{ position: 'absolute', inset: 0 }}>
        <RouteList />
        <div
            style={{
                position: 'absolute',
                inset: 0,
                background: 'rgba(0,0,0,0.6)',
            }}
        />
        <div
            style={{
                position: 'absolute',
                left: 210,
                top: 46,
                width: 1020,
                height: 800,
                background: C.panel,
                border: `1px solid ${C.lineStrong}`,
                borderRadius: 14,
                fontFamily: FONT,
                color: C.ink,
                overflow: 'hidden',
            }}
        >
            <div style={{ padding: '22px 26px' }}>
                <div style={{ fontFamily: MONO, fontSize: 19 }}>
                    <span style={{ color: C.ink2 }}>POST</span>{' '}
                    <b>/checkout/payment</b>{' '}
                    <span style={{ color: C.ink2 }}>
                        checkout.payment shop.example.com
                    </span>
                </div>
                <div style={{ marginTop: 6, fontSize: 15, color: C.ink2 }}>
                    1,284 requests · p95 5.32 s · error rate 18.4%
                </div>
            </div>
            <div style={{ display: 'flex', borderTop: `1px solid ${C.line}` }}>
                <div style={{ width: 480, padding: '18px 26px' }}>
                    <div
                        style={{
                            fontSize: 12,
                            letterSpacing: 0.8,
                            color: C.ink3,
                        }}
                    >
                        RESPONSE TIME OVER THE PERIOD (P50 AND P95)
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'flex-end',
                            gap: 5,
                            height: 150,
                            marginTop: 14,
                        }}
                    >
                        {[
                            2, 3, 2, 3, 2, 2, 3, 2, 3, 2, 2, 3, 2, 3, 2, 2, 3,
                            2, 3, 2, 14, 30, 31, 30,
                        ].map((v, i) => (
                            <i
                                key={i}
                                style={{
                                    flex: 1,
                                    height: v * 4.6,
                                    background: i > 19 ? '#3b5f99' : C.info,
                                    borderRadius: 2,
                                    display: 'block',
                                }}
                            />
                        ))}
                    </div>
                    <div
                        style={{
                            marginTop: 22,
                            fontSize: 12,
                            letterSpacing: 0.8,
                            color: C.ink3,
                        }}
                    >
                        STATUS CODES
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            height: 10,
                            borderRadius: 5,
                            overflow: 'hidden',
                            marginTop: 12,
                        }}
                    >
                        <i
                            style={{
                                flex: 80,
                                background: C.ok,
                                display: 'block',
                            }}
                        />
                        <i
                            style={{
                                flex: 2,
                                background: C.warn,
                                display: 'block',
                            }}
                        />
                        <i
                            style={{
                                flex: 18,
                                background: C.err,
                                display: 'block',
                            }}
                        />
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            gap: 16,
                            marginTop: 10,
                            fontFamily: MONO,
                            fontSize: 13,
                            color: C.ink2,
                        }}
                    >
                        <span>2xx 1,024</span>
                        <span>3xx 0</span>
                        <span>4xx 24</span>
                        <span>5xx 236</span>
                    </div>
                </div>
                <div style={{ flex: 1, borderLeft: `1px solid ${C.line}` }}>
                    <div
                        style={{
                            padding: '18px 22px 10px',
                            fontSize: 12,
                            letterSpacing: 0.8,
                            color: C.ink3,
                        }}
                    >
                        SLOWEST REQUESTS
                    </div>
                    {SLOWEST.map(
                        ([time, host, queries, id, took, status], i) => (
                            <div
                                key={id}
                                style={{
                                    padding: '12px 22px',
                                    borderTop: `1px solid ${C.line}`,
                                    fontFamily: MONO,
                                    fontSize: 14,
                                    background: i
                                        ? undefined
                                        : 'rgba(240,169,59,0.05)',
                                }}
                            >
                                <div style={{ color: C.ink2 }}>
                                    {time} · {host} · {queries} ·
                                </div>
                                <div style={{ color: C.ink2 }}>{id}</div>
                                <div
                                    style={{
                                        marginTop: 8,
                                        display: 'flex',
                                        gap: 10,
                                        alignItems: 'center',
                                    }}
                                >
                                    {took} <Badge t="err">{status}</Badge>
                                </div>
                            </div>
                        ),
                    )}
                </div>
            </div>
        </div>
    </div>
);

type Step = {
    at: string;
    kind: 'Query' | 'HTTP' | 'Log' | 'Exception';
    what: ReactNode;
    where?: string;
    start: number;
    took: number;
    label?: string;
};

const KIND_TONE = {
    Query: 'amber',
    HTTP: 'info',
    Log: 'err',
    Exception: 'err',
} as const;
const BAR = { Query: C.amber, HTTP: C.info, Log: C.err, Exception: C.err };

const Sql = ({ children }: { children: string }) => (
    <span>
        {children.split(/("[^"]+"|\?)/).map((part, i) => (
            <span
                key={i}
                style={{
                    color: part.startsWith('"')
                        ? '#96d0ff'
                        : part === '?'
                          ? C.ink
                          : '#f47067',
                }}
            >
                {part}
            </span>
        ))}
    </span>
);

const TOTAL = 5310;

const STEPS: Step[] = [
    {
        at: '+12 ms',
        kind: 'Query',
        what: <Sql>{'select * from "carts" where "id" = ? limit 1'}</Sql>,
        where: 'app/Http/Controllers/CheckoutController.php:31',
        start: 12,
        took: 1.2,
        label: '1.2 ms',
    },
    ...Array.from({ length: 24 }, (_, i): Step => ({
        at: `+${15 + i * 14} ms`,
        kind: 'Query',
        what: <Sql>{'select * from "products" where "id" = ? limit 1'}</Sql>,
        where: 'app/Models/CartItem.php:24',
        start: 15 + i * 14,
        took: 11,
        label: `${(10 + ((i * 7) % 5)).toFixed(1)} ms`,
    })),
    {
        at: '+352 ms',
        kind: 'HTTP',
        what: (
            <span>
                POST{' '}
                <span style={{ color: C.ink }}>
                    api.payments.example/v1/charges
                </span>
            </span>
        ),
        start: 352,
        took: 5000,
        label: '5.00 s',
    },
    {
        at: '+5.35 s',
        kind: 'Log',
        what: (
            <span>
                <span style={{ color: C.ink3 }}>payments</span>{' '}
                <span style={{ color: C.ink }}>
                    Payment for order 48215 failed: timed out
                </span>
            </span>
        ),
        start: 5300,
        took: 0,
    },
    {
        at: '+5.35 s',
        kind: 'Exception',
        what: (
            <span style={{ color: C.err, fontFamily: FONT }}>
                SHOP-14 Illuminate\Http\Client\ConnectionException
            </span>
        ),
        start: 5300,
        took: 0,
    },
];

const STEP_HEIGHT = 56;

const StepRow = ({ step, first }: { step: Step; first: boolean }) => (
    <div
        style={{
            display: 'flex',
            alignItems: 'center',
            gap: 14,
            height: STEP_HEIGHT,
            padding: '0 18px',
            borderTop: first ? undefined : `1px solid ${C.line}`,
        }}
    >
        <span
            style={{ width: 70, fontFamily: MONO, fontSize: 13, color: C.ink2 }}
        >
            {step.at}
        </span>
        <span style={{ width: 96 }}>
            <Badge t={KIND_TONE[step.kind]}>{step.kind}</Badge>
        </span>
        <div
            style={{
                flex: 1,
                minWidth: 0,
                fontFamily: MONO,
                fontSize: 14,
                color: C.ink2,
            }}
        >
            <div
                style={{
                    whiteSpace: 'nowrap',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                }}
            >
                {step.what}
            </div>
            {step.where ? (
                <div style={{ marginTop: 2, fontSize: 12, color: C.ink3 }}>
                    {step.where}
                </div>
            ) : null}
        </div>
        <div
            style={{
                position: 'relative',
                width: 280,
                height: 8,
                borderRadius: 4,
                background: C.panel2,
            }}
        >
            <i
                style={{
                    position: 'absolute',
                    left: (step.start / TOTAL) * 280,
                    width: Math.max(3, (step.took / TOTAL) * 280),
                    height: 8,
                    borderRadius: 4,
                    background: BAR[step.kind],
                    display: 'block',
                }}
            />
        </div>
        <span
            style={{
                width: 70,
                textAlign: 'right',
                fontFamily: MONO,
                fontSize: 13,
                color: C.ink2,
            }}
        >
            {step.label ?? ''}
        </span>
    </div>
);

const Trace = () => (
    <AppShell
        active="Requests"
        crumbs={['Nordic Goods', 'Storefront', 'Trace', '5f50659c']}
        period={false}
        height={1900}
    >
        <div style={{ fontFamily: MONO, fontSize: 24 }}>
            <b>POST /checkout/payment</b>{' '}
            <span style={{ fontSize: 17, color: C.ink2 }}>
                checkout.payment shop.example.com
            </span>
        </div>
        <div style={{ marginTop: 6, fontSize: 15, color: C.ink2 }}>
            500 · 5.31 s · web-2
        </div>
        <div style={{ display: 'flex', gap: 10, marginTop: 16 }}>
            {[
                '25 queries · 268 ms',
                '1 outgoing call',
                '1 log line',
                '1 exception',
            ].map((pill) => (
                <span
                    key={pill}
                    style={{
                        padding: '6px 14px',
                        borderRadius: 16,
                        border: `1px solid ${C.lineStrong}`,
                        fontSize: 14,
                        color: C.ink2,
                    }}
                >
                    {pill}
                </span>
            ))}
        </div>
        <Card style={{ marginTop: 18 }}>
            <CardHead
                title="Timeline"
                hint="Bars show how long each step took, placed where it started."
            />
            <StepRow step={STEPS[0]} first />
            <Spot
                at={6.6}
                out={8.5}
                label="The same query, once for every item in the cart"
                pad={0}
            >
                {STEPS.slice(1, 25).map((step, i) => (
                    <StepRow key={i} step={step} first={false} />
                ))}
            </Spot>
            <Spot
                at={8.9}
                out={11.3}
                label="The payment API never answered: 5 s"
                pad={0}
            >
                <StepRow step={STEPS[25]} first={false} />
            </Spot>
            <StepRow step={STEPS[26]} first={false} />
            <StepRow step={STEPS[27]} first={false} />
        </Card>
    </AppShell>
);

export const Performance = () => {
    const t = useTime();
    const cam = camera(t, [
        { t: 0, x: 720, y: 450, s: 1 },
        { t: 2.4, x: 620, y: 250, s: 1.3 },
        { t: OPEN_ROUTE + 0.6, x: 720, y: 450, s: 1 },
        { t: 4.8, x: 1000, y: 250, s: 1.3 },
        { t: OPEN_TRACE + 0.7, x: 720, y: 450, s: 1 },
        { t: 7.6, x: 720, y: 760, s: 1 },
        { t: 9.4, x: 760, y: 1640, s: 1.3 },
        { t: 11.4, x: 720, y: 1500, s: 1 },
    ]);

    return (
        <Feature
            duration={DURATION}
            kicker="Performance"
            title="Find out why it is *slow*"
            subs={[
                ['Response times, error rate and Apdex for every route.', 0.9],
                ['Open a route for its slowest requests.', 3.1],
                ['Down to a single trace: every query and call.', 5.8],
                [
                    '24 queries for one cart, then a call that never came back.',
                    8.9,
                ],
            ]}
        >
            <Camera {...cam} height={1900}>
                <PageSwap
                    at={OPEN_TRACE + 0.2}
                    before={
                        <PageSwap
                            at={OPEN_ROUTE + 0.2}
                            before={<RouteList />}
                            after={<RouteModal />}
                        />
                    }
                    after={<Trace />}
                />
                <Cursor
                    show={1.6}
                    hide={OPEN_TRACE + 0.5}
                    keys={[
                        { t: 1.6, x: 1000, y: 650 },
                        { t: 2.5, x: 420, y: 236 },
                        { t: OPEN_ROUTE, x: 420, y: 236, click: true },
                        { t: 4.6, x: 900, y: 230 },
                        { t: OPEN_TRACE, x: 900, y: 230, click: true },
                    ]}
                />
            </Camera>
        </Feature>
    );
};
