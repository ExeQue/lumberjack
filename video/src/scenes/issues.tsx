import type { ReactNode } from 'react';
import { camera, progress, sec, useTime } from '../anim';
import { C, MONO, type Tone } from '../theme';
import {
    AppShell,
    Badge,
    Bars,
    Button,
    Camera,
    Card,
    colStyle,
    Cursor,
    PageHeader,
    Spot,
    Stat,
    THead,
    type Col,
} from '../ui';
import { Feature, PageSwap } from './feature';

const DURATION = 12;
export const ISSUES = sec(DURATION);

const CLICK = 3.9;

type Row = {
    id: string;
    title: string;
    message: string;
    where: string;
    badges: [string, Tone][];
    events: number;
    users: number;
    seen: string;
    assignee: string;
    spark: number[];
};

const ROWS: Row[] = [
    {
        id: 'SHOP-14',
        title: 'Illuminate\\Http\\Client\\ConnectionException',
        message:
            'cURL error 28: Operation timed out after 5000 milliseconds (api.payments.example)',
        where: 'app/Services/PaymentGateway.php:58',
        badges: [['Server', 'muted']],
        events: 312,
        users: 184,
        seen: 'just now',
        assignee: 'Unassigned',
        spark: [
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 9,
            22,
        ],
    },
    {
        id: 'SHOP-2',
        title: 'RedisException',
        message: 'read error on connection to redis-cache.internal:6379',
        where: 'app/Http/Middleware/TrackRecentlyViewed.php:31',
        badges: [
            ['Server', 'muted'],
            ['infra', 'ok'],
        ],
        events: 74,
        users: 26,
        seen: '5 days ago',
        assignee: 'Unassigned',
        spark: [],
    },
    {
        id: 'SHOP-4',
        title: 'TypeError',
        message: "Cannot read properties of undefined (reading 'price')",
        where: 'https://shop.example.com/build/assets/VariantPicker-4f9c2a1b.js:1',
        badges: [
            ['Browser', 'muted'],
            ['customer-facing', 'info'],
        ],
        events: 31,
        users: 12,
        seen: '5 days ago',
        assignee: 'Unassigned',
        spark: [],
    },
    {
        id: 'SHOP-10',
        title: 'App\\Exceptions\\CouponExpiredException',
        message: 'Coupon SPRING25 expired on 2026-10-31',
        where: 'app/Cart/ApplyCoupon.php:37',
        badges: [
            ['Server', 'muted'],
            ['Regression', 'err'],
        ],
        events: 80,
        users: 35,
        seen: '5 days ago',
        assignee: 'Ida Holm',
        spark: [],
    },
];

const LIST_COLS: Col[] = [
    ['Issue', 'flex'],
    ['Last 24 h', 90],
    ['Occurrences', 100, 'right'],
    ['Users', 60, 'right'],
    ['Last seen', 100],
    ['Assignee', 100],
];

const Segments = ({ items }: { items: string[] }) => (
    <span
        style={{
            display: 'inline-flex',
            border: `1px solid ${C.line}`,
            borderRadius: 7,
            overflow: 'hidden',
            fontSize: 13,
        }}
    >
        {items.map((item, i) => (
            <span
                key={item}
                style={{
                    padding: '6px 11px',
                    background: i ? undefined : C.panel2,
                    color: i ? C.ink2 : C.ink,
                    borderLeft: i ? `1px solid ${C.line}` : undefined,
                }}
            >
                {item}
            </span>
        ))}
    </span>
);

const Select = ({
    children,
    width,
}: {
    children: ReactNode;
    width: number;
}) => (
    <span
        style={{
            display: 'inline-flex',
            width,
            padding: '6px 12px',
            border: `1px solid ${C.line}`,
            borderRadius: 7,
            fontSize: 13,
        }}
    >
        {children}
        <span style={{ marginLeft: 'auto', color: C.ink3 }}>⌄</span>
    </span>
);

const IssueList = () => (
    <AppShell
        active="Issues"
        crumbs={['Nordic Goods', 'Storefront', 'Issues']}
        period={false}
    >
        <PageHeader title="Issues" />
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            <Segments
                items={['Open 7', 'In progress 1', 'Resolved 2', 'Ignored 1']}
            />
            <Segments items={['All', 'Server', 'Browser', 'Log']} />
            <Select width={130}>All releases</Select>
            <Select width={120}>Everyone</Select>
            <Select width={120}>All labels</Select>
        </div>
        <div
            style={{
                marginTop: 10,
                padding: '7px 12px',
                border: `1px solid ${C.line}`,
                borderRadius: 7,
                fontSize: 13,
                color: C.ink3,
            }}
        >
            Search message, class or number
        </div>
        <Card style={{ marginTop: 16 }}>
            <THead cols={LIST_COLS} />
            {ROWS.map((row, i) => (
                <Slide key={row.id} at={0.5 + i * 0.12}>
                    <div
                        style={{
                            display: 'flex',
                            gap: 14,
                            alignItems: 'center',
                            padding: '13px 18px',
                            borderTop: i ? `1px solid ${C.line}` : undefined,
                        }}
                    >
                        <div style={colStyle('flex')}>
                            <div
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 8,
                                }}
                            >
                                <span
                                    style={{
                                        fontFamily: MONO,
                                        fontSize: 13,
                                        color: C.ink3,
                                    }}
                                >
                                    {row.id}
                                </span>
                                <span
                                    style={{
                                        fontFamily: MONO,
                                        fontSize: 14,
                                        fontWeight: 500,
                                    }}
                                >
                                    {row.title}
                                </span>
                                {row.badges.map(([label, t]) => (
                                    <Badge key={label} t={t}>
                                        {label}
                                    </Badge>
                                ))}
                            </div>
                            <div
                                style={{
                                    marginTop: 5,
                                    fontSize: 14,
                                    color: C.ink2,
                                    whiteSpace: 'nowrap',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                }}
                            >
                                {row.message}
                            </div>
                            <div
                                style={{
                                    marginTop: 3,
                                    fontFamily: MONO,
                                    fontSize: 12,
                                    color: C.ink3,
                                }}
                            >
                                {row.where}
                            </div>
                        </div>
                        <div style={colStyle(90)}>
                            {row.spark.length ? (
                                <Bars
                                    values={row.spark.map((v) => v + 0.2)}
                                    at={0.9}
                                    height={26}
                                    gap={1}
                                    duration={0.6}
                                    color={C.err}
                                />
                            ) : (
                                <i
                                    style={{
                                        display: 'block',
                                        height: 2,
                                        background: C.line,
                                    }}
                                />
                            )}
                        </div>
                        <span
                            style={{
                                ...colStyle(100),
                                textAlign: 'right',
                                fontFamily: MONO,
                                fontSize: 14,
                            }}
                        >
                            {row.events}
                        </span>
                        <span
                            style={{
                                ...colStyle(60),
                                textAlign: 'right',
                                fontFamily: MONO,
                                fontSize: 14,
                            }}
                        >
                            {row.users}
                        </span>
                        <span
                            style={{
                                ...colStyle(100),
                                fontSize: 14,
                                color: C.ink2,
                            }}
                        >
                            {row.seen}
                        </span>
                        <span
                            style={{
                                ...colStyle(100),
                                fontSize: 14,
                                color: C.ink2,
                            }}
                        >
                            {row.assignee}
                        </span>
                    </div>
                </Slide>
            ))}
        </Card>
    </AppShell>
);

const Slide = ({ at, children }: { at: number; children: ReactNode }) => {
    const t = useTime();
    const p = progress(t, at, 0.6);

    return (
        <div style={{ opacity: p, transform: `translateX(${(1 - p) * 30}px)` }}>
            {children}
        </div>
    );
};

const K = ({ children }: { children: ReactNode }) => (
    <span style={{ color: '#f47067' }}>{children}</span>
);
const F = ({ children }: { children: ReactNode }) => (
    <span style={{ color: '#dcbdfb' }}>{children}</span>
);
const T = ({ children }: { children: ReactNode }) => (
    <span style={{ color: '#6cb6ff' }}>{children}</span>
);
const S = ({ children }: { children: ReactNode }) => (
    <span style={{ color: '#96d0ff' }}>{children}</span>
);

const CODE: [number, ReactNode][] = [
    [
        56,
        <span key="56">
            <K>public function</K> <F>charge</F>(<T>Order</T> $order):{' '}
            <T>Charge</T>
        </span>,
    ],
    [57, <span key="57">{'{'}</span>],
    [
        58,
        <span key="58">
            {'    '}$response = <K>$this</K>-&gt;http-&gt;<F>post</F>(
            <S>'/v1/charges'</S>, <K>$this</K>-&gt;<F>payloadFor</F>($order));
        </span>,
    ],
    [59, <span key="59"> </span>],
    [
        60,
        <span key="60">
            {'    '}
            <K>return</K> <T>Charge</T>::<F>fromResponse</F>($response-&gt;
            <F>throw</F>());
        </span>,
    ],
];

const HOURLY = [...Array(21).fill(0), 3, 96, 213];

const IssueDetail = () => (
    <AppShell
        active="Issues"
        crumbs={['Nordic Goods', 'Storefront', 'Issues', 'SHOP-14']}
        period={false}
    >
        <div style={{ display: 'flex', gap: 8 }}>
            <Badge t="err">Open</Badge>
            <Badge t="err">Escalating</Badge>
            <Badge>Server</Badge>
            <Badge>production</Badge>
            <Badge t="info">New</Badge>
        </div>
        <div
            style={{
                marginTop: 14,
                display: 'flex',
                gap: 14,
                alignItems: 'baseline',
                fontFamily: MONO,
                fontSize: 24,
            }}
        >
            <span style={{ color: C.ink3 }}>SHOP-14</span>
            <span>Illuminate\Http\Client\ConnectionException</span>
        </div>
        <div style={{ marginTop: 8, fontSize: 16, color: C.ink2 }}>
            cURL error 28: Operation timed out after 5000 milliseconds
            (api.payments.example)
        </div>
        <div
            style={{
                marginTop: 4,
                fontFamily: MONO,
                fontSize: 13,
                color: C.ink3,
            }}
        >
            app/Services/PaymentGateway.php:58
        </div>
        <div style={{ display: 'flex', gap: 10, marginTop: 16 }}>
            <Button primary>Resolve</Button>
            <Button>Ignore</Button>
            <Button>Start working</Button>
            <Button>Watch</Button>
            <Button>Unassigned ⌄</Button>
            <Button>Create GitHub issue</Button>
            <Button>Copy as Markdown</Button>
        </div>
        <div
            style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(6, 1fr)',
                gap: 12,
                marginTop: 18,
            }}
        >
            <Stat label="Occurrences" value="312" />
            <Spot at={4.9} out={6.3} pad={4}>
                <Stat label="Users affected" value="184" />
            </Spot>
            <Stat
                label="First seen"
                value={<span style={{ fontSize: 20 }}>31 minutes ago</span>}
            />
            <Stat
                label="Last seen"
                value={<span style={{ fontSize: 20 }}>just now</span>}
            />
            <Spot at={6.6} out={8.4} label="It came with v2.16.2" pad={4}>
                <Stat
                    label="Releases"
                    value={<span style={{ fontSize: 22 }}>v2.16.2</span>}
                />
            </Spot>
            <Stat
                label="Hosts"
                value={
                    <span style={{ fontSize: 20 }}>web-1, web-2, web-3</span>
                }
            />
        </div>
        <Card title="Occurrences per hour, 24 h" style={{ marginTop: 18 }}>
            <div style={{ padding: '14px 18px' }}>
                <Bars
                    values={HOURLY.map((v) => v + 1)}
                    at={CLICK + 0.6}
                    height={90}
                    color={C.err}
                />
            </div>
        </Card>
        <Card style={{ marginTop: 18 }}>
            <div
                style={{
                    display: 'flex',
                    gap: 26,
                    padding: '12px 20px',
                    borderBottom: `1px solid ${C.line}`,
                    fontSize: 15,
                    color: C.ink3,
                }}
            >
                <span
                    style={{
                        color: C.ink,
                        boxShadow: `0 15px 0 -13px ${C.amber}`,
                    }}
                >
                    Stacktrace
                </span>
                <span>Occurrences 50</span>
                <span>Notes 0</span>
                <span>History</span>
            </div>
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    padding: '10px 20px',
                    fontFamily: MONO,
                    fontSize: 14,
                    borderBottom: `1px solid ${C.line}`,
                }}
            >
                app/Services/PaymentGateway.php
                <span style={{ color: C.ink3 }}>:58</span>&nbsp;
                <span style={{ color: C.info }}>
                    App\Services\PaymentGateway::charge()
                </span>
                <span style={{ marginLeft: 'auto' }}>
                    <Badge t="amber">app</Badge>
                </span>
            </div>
            <div
                style={{
                    fontFamily: MONO,
                    fontSize: 15,
                    padding: '8px 0',
                    whiteSpace: 'pre',
                }}
            >
                {CODE.map(([n, code]) =>
                    n === 58 ? (
                        <Spot
                            key={n}
                            at={8.7}
                            out={11.3}
                            label="Down to the line in your own code"
                            pad={0}
                        >
                            <div
                                style={{
                                    display: 'flex',
                                    background: 'rgba(242,112,95,0.12)',
                                    lineHeight: '30px',
                                }}
                            >
                                <span
                                    style={{
                                        width: 70,
                                        textAlign: 'right',
                                        paddingRight: 20,
                                        color: C.err,
                                    }}
                                >
                                    &gt; {n}
                                </span>
                                {code}
                            </div>
                        </Spot>
                    ) : (
                        <div
                            key={n}
                            style={{ display: 'flex', lineHeight: '30px' }}
                        >
                            <span
                                style={{
                                    width: 70,
                                    textAlign: 'right',
                                    paddingRight: 20,
                                    color: C.ink3,
                                }}
                            >
                                {n}
                            </span>
                            {code}
                        </div>
                    ),
                )}
            </div>
            <div
                style={{
                    padding: '10px 20px',
                    borderTop: `1px solid ${C.line}`,
                    fontSize: 14,
                    color: C.ink2,
                }}
            >
                Show 14 vendor frames
            </div>
        </Card>
    </AppShell>
);

export const Issues = () => {
    const t = useTime();
    const cam = camera(t, [
        { t: 0, x: 720, y: 450, s: 1 },
        { t: 3.4, x: 640, y: 260, s: 1.3 },
        { t: CLICK + 0.6, x: 720, y: 450, s: 1 },
        { t: 6.6, x: 1000, y: 330, s: 1.4 },
        { t: 9, x: 700, y: 760, s: 1.35 },
        { t: 11.2, x: 720, y: 560, s: 1 },
    ]);

    return (
        <Feature
            side="left"
            duration={DURATION}
            kicker="Issues"
            title="See exactly what *broke*"
            subs={[
                [
                    'Exceptions, browser errors and error logs become issues.',
                    0.9,
                ],
                ['How often it happens, and who it hits.', 4.6],
                ['Which release brought it.', 6.5],
                ['Down to the line in your own code.', 8.7],
            ]}
        >
            <Camera {...cam} height={1100}>
                <PageSwap
                    at={CLICK + 0.25}
                    before={<IssueList />}
                    after={<IssueDetail />}
                />
                <Cursor
                    show={2.2}
                    hide={CLICK + 0.5}
                    keys={[
                        { t: 2.2, x: 1100, y: 640 },
                        { t: 3.5, x: 600, y: 306 },
                        { t: CLICK, x: 600, y: 306, click: true },
                    ]}
                />
            </Camera>
        </Feature>
    );
};
