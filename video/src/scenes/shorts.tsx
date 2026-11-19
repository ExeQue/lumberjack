import type { ReactNode } from 'react';
import { AbsoluteFill } from 'remotion';
import { camera, countUp, easeInOut, progress, sec, useTime } from '../anim';
import { C, FONT, MONO, type Tone } from '../theme';
import {
    AppShell,
    Badge,
    Button,
    Camera,
    Card,
    CardHead,
    colStyle,
    Dot,
    Headline,
    Line,
    Logo,
    PageHeader,
    Spot,
    THead,
    type Col,
} from '../ui';
import { Feature, PageSwap } from './feature';

const DURATION = 6.5;
export const SHORT = sec(DURATION);

const SWAP = 3.3;

const Cell = ({
    w,
    children,
    right = false,
    mono = true,
    color,
}: {
    w: number | 'flex';
    children: ReactNode;
    right?: boolean;
    mono?: boolean;
    color?: string;
}) => (
    <span
        style={{
            ...colStyle(w),
            textAlign: right ? 'right' : undefined,
            fontFamily: mono ? MONO : FONT,
            fontSize: 14,
            color,
            whiteSpace: 'nowrap',
            overflow: 'hidden',
            textOverflow: 'ellipsis',
        }}
    >
        {children}
    </span>
);

const TRow = ({
    children,
    first = false,
}: {
    children: ReactNode;
    first?: boolean;
}) => (
    <div
        style={{
            display: 'flex',
            gap: 14,
            alignItems: 'center',
            minHeight: 54,
            padding: '8px 18px',
            borderTop: first ? undefined : `1px solid ${C.line}`,
        }}
    >
        {children}
    </div>
);

// ---- Background work ----------------------------------------------------------------

const QUEUE_COLS: Col[] = [
    ['Queue', 'flex'],
    ['Waiting', 80, 'right'],
    ['Workers', 80, 'right'],
    ['Oldest pending', 130, 'right'],
    ['P95 wait', 90, 'right'],
    ['Size', 200],
    ['', 70],
];

const QUEUES = [
    {
        name: 'default',
        waiting: 0,
        oldest: '-',
        wait: '666 ms',
        size: [1, 2, 1, 1, 2, 1, 3, 1, 2, 1, 2, 1],
    },
    {
        name: 'mail',
        waiting: 0,
        oldest: '-',
        wait: '621 ms',
        size: [1, 1, 2, 1, 1, 3, 1, 2, 1, 1, 2, 1],
    },
    {
        name: 'webhooks',
        waiting: 248,
        oldest: '4 min',
        wait: '3.9 min',
        size: [1, 2, 1, 2, 1, 2, 8, 40, 90, 150, 210, 248],
        bad: true,
    },
];

const JOB_COLS: Col[] = [
    ['Job class', 'flex'],
    ['Queue', 100],
    ['Runs', 70, 'right'],
    ['Failure rate', 110, 'right'],
    ['P95 duration', 110, 'right'],
    ['Outcome', 200],
];

const JobsPage = () => {
    const t = useTime();

    return (
        <AppShell active="Jobs" crumbs={['Nordic Goods', 'Storefront', 'Jobs']}>
            <PageHeader
                title="Jobs"
                subtitle="1,367 runs across 5 job classes."
            />
            <Card>
                <CardHead
                    title="Queues"
                    hint="Jobs waiting on each queue, as the agents report it every minute."
                />
                <THead cols={QUEUE_COLS} />
                {QUEUES.map((queue, i) => {
                    const waiting = Math.round(
                        countUp(t, 0.6, 2.2, queue.waiting),
                    );
                    const row = (
                        <TRow first={i === 0}>
                            <span
                                style={{
                                    ...colStyle('flex'),
                                    display: 'flex',
                                    gap: 10,
                                    alignItems: 'center',
                                }}
                            >
                                <Badge mono>redis</Badge>
                                <span
                                    style={{ fontFamily: MONO, fontSize: 15 }}
                                >
                                    {queue.name}
                                </span>
                            </span>
                            <Cell
                                w={80}
                                right
                                color={queue.bad ? C.err : undefined}
                            >
                                {waiting}
                            </Cell>
                            <Cell w={80} right>
                                {queue.bad ? '2' : '-'}
                            </Cell>
                            <Cell
                                w={130}
                                right
                                color={queue.bad ? C.err : undefined}
                            >
                                {queue.oldest}
                            </Cell>
                            <Cell w={90} right>
                                {queue.wait}
                            </Cell>
                            <span style={colStyle(200)}>
                                <Line
                                    values={queue.size}
                                    at={0.5}
                                    width={190}
                                    height={34}
                                    color={queue.bad ? C.err : C.info}
                                    fill={false}
                                    strokeWidth={2}
                                    duration={1.6}
                                />
                            </span>
                            <span style={colStyle(70)}>
                                <Button>Alert</Button>
                            </span>
                        </TRow>
                    );

                    return queue.bad ? (
                        <Spot
                            key={queue.name}
                            at={1.8}
                            out={3.1}
                            label="248 webhooks waiting"
                            pad={0}
                        >
                            {row}
                        </Spot>
                    ) : (
                        <div key={queue.name}>{row}</div>
                    );
                })}
            </Card>
            <Card style={{ marginTop: 18 }}>
                <THead cols={JOB_COLS} />
                {[
                    [
                        'App\\Jobs\\',
                        'HandleStripeWebhook',
                        'webhooks',
                        '294',
                        '0.34%',
                        '501 ms',
                    ],
                    [
                        'App\\Jobs\\',
                        'SyncInventory',
                        'default',
                        '236',
                        '0%',
                        '765 ms',
                    ],
                    [
                        'App\\Mail\\',
                        'OrderConfirmation',
                        'mail',
                        '197',
                        '0%',
                        '1.18 s',
                    ],
                ].map(([ns, name, queue, runs, rate, p95], i) => (
                    <TRow key={name} first={i === 0}>
                        <Cell w="flex">
                            <span style={{ color: C.ink3 }}>{ns}</span>
                            {name}
                        </Cell>
                        <span style={colStyle(100)}>
                            <Badge>{queue}</Badge>
                        </span>
                        <Cell w={70} right>
                            {runs}
                        </Cell>
                        <Cell
                            w={110}
                            right
                            color={rate === '0%' ? C.ink3 : undefined}
                        >
                            {rate}
                        </Cell>
                        <Cell w={110} right>
                            {p95}
                        </Cell>
                        <span style={colStyle(200)}>
                            <i
                                style={{
                                    display: 'block',
                                    height: 8,
                                    borderRadius: 4,
                                    background: C.ok,
                                }}
                            />
                        </span>
                    </TRow>
                ))}
            </Card>
        </AppShell>
    );
};

const TASK_COLS: Col[] = [
    ['Task', 'flex'],
    ['Cron', 110],
    ['Status', 100],
    ['Last run', 120],
    ['Duration', 90, 'right'],
    ['Next run', 120],
    ['Recent runs', 170],
];

const TASKS: {
    command: string;
    cron: string;
    status: [string, Tone];
    last: string;
    took: string;
    next: string;
    runs: Tone[];
}[] = [
    {
        command: 'backup:run',
        cron: '0 2 * * *',
        status: ['Missed', 'warn'],
        last: '1 day ago',
        took: '41.2 s',
        next: 'in 22 hours',
        runs: ['ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'warn'],
    },
    {
        command: 'carts:prune-abandoned',
        cron: '0 * * * *',
        status: ['Finished', 'ok'],
        last: '1 hour ago',
        took: '2.87 s',
        next: 'in 51 minutes',
        runs: Array(16).fill('ok'),
    },
    {
        command: 'inventory:sync',
        cron: '*/15 * * * *',
        status: ['Finished', 'ok'],
        last: '4 minutes ago',
        took: '49.6 s',
        next: 'in 11 minutes',
        runs: Array(16).fill('ok'),
    },
    {
        command: 'reports:daily-sales',
        cron: '30 6 * * *',
        status: ['Finished', 'ok'],
        last: '21 hours ago',
        took: '17.8 s',
        next: 'in 3 hours',
        runs: ['ok', 'ok', 'ok', 'err', 'ok', 'ok', 'ok'],
    },
];

const SchedulePage = () => (
    <AppShell
        active="Schedule"
        crumbs={['Nordic Goods', 'Storefront', 'Schedule']}
        tabs={{ items: ['Schedule', 'Commands'], active: 'Schedule' }}
    >
        <PageHeader title="Schedule" subtitle="4 scheduled tasks." />
        <Card>
            <CardHead
                title="Schedule"
                hint="A task that has not run 5 minutes after its time is marked missed and becomes an issue."
            />
            <THead cols={TASK_COLS} />
            {TASKS.map((task, i) => {
                const row = (
                    <TRow first={i === 0}>
                        <Cell w="flex">
                            artisan {task.command}{' '}
                            <span style={{ color: C.ink3 }}>production</span>
                        </Cell>
                        <Cell w={110}>{task.cron}</Cell>
                        <span style={colStyle(100)}>
                            <Badge t={task.status[1]}>{task.status[0]}</Badge>
                        </span>
                        <Cell w={120} mono={false} color={C.ink2}>
                            {task.last}
                        </Cell>
                        <Cell w={90} right>
                            {task.took}
                        </Cell>
                        <Cell w={120} mono={false} color={C.ink2}>
                            {task.next}
                        </Cell>
                        <span
                            style={{
                                ...colStyle(170),
                                display: 'flex',
                                gap: 3,
                            }}
                        >
                            {task.runs.map((run, r) => (
                                <i
                                    key={r}
                                    style={{
                                        width: 7,
                                        height: 18,
                                        borderRadius: 3,
                                        display: 'block',
                                        background:
                                            run === 'ok'
                                                ? C.ok
                                                : run === 'warn'
                                                  ? C.warn
                                                  : C.err,
                                    }}
                                />
                            ))}
                        </span>
                    </TRow>
                );

                return i === 0 ? (
                    <Spot
                        key={task.command}
                        at={4.2}
                        out={6.0}
                        label="The backup never ran last night"
                        pad={0}
                    >
                        {row}
                    </Spot>
                ) : (
                    <div key={task.command}>{row}</div>
                );
            })}
        </Card>
    </AppShell>
);

export const Jobs = () => {
    const t = useTime();
    const cam = camera(t, [
        { t: 0, x: 720, y: 450, s: 1.05 },
        { t: 2.0, x: 760, y: 330, s: 1.35 },
        { t: SWAP + 0.5, x: 720, y: 450, s: 1 },
        { t: 5.0, x: 780, y: 320, s: 1.35 },
    ]);

    return (
        <Feature
            duration={DURATION}
            kicker="Background work"
            title="Watch the work nobody *sees*"
            subs={[
                ['Which jobs failed, and which queues back up.', 0.8],
                ['Scheduled tasks that failed, or never ran.', 3.6],
            ]}
        >
            <Camera {...cam}>
                <PageSwap
                    at={SWAP}
                    before={<JobsPage />}
                    after={<SchedulePage />}
                />
            </Camera>
        </Feature>
    );
};

// ---- Logs ---------------------------------------------------------------------------

const LOG_LINES: [Tone, string, string, string][] = [
    ['info', 'info', 'payments', 'Order 48230 paid · 129.00 EUR'],
    ['err', 'error', 'payments', 'Payment for order 48229 failed: timed out'],
    [
        'warn',
        'warning',
        'payments',
        'Retrying payment for order 48229 (2 of 3)',
    ],
    ['info', 'info', 'default', 'Mail OrderShipped sent to 1 recipient'],
    ['info', 'info', 'payments', 'Order 48228 paid · 54.50 EUR'],
    ['muted', 'debug', 'search', 'Reindexed 12 products'],
    ['warn', 'warning', 'payments', 'Payment provider answered slowly · 2.8 s'],
    ['err', 'error', 'payments', 'Payment for order 48215 failed: timed out'],
    ['info', 'info', 'default', 'Webhook stripe.charge.succeeded handled'],
    ['info', 'info', 'payments', 'Order 48214 paid · 310.00 EUR'],
    ['warn', 'warning', 'default', 'Stock for SKU OAK-LAMP below 5'],
    ['info', 'info', 'payments', 'Order 48213 paid · 76.20 EUR'],
    ['info', 'info', 'default', 'Session started for customer 9921'],
];

const QUERY = 'payment';

const LogsPage = () => {
    const t = useTime();
    const typed = QUERY.slice(
        0,
        Math.floor(progress(t, 1.2, 0.8, (n) => n) * QUERY.length),
    );
    const filtered = t > 2.3;
    const arrived = Math.floor(progress(t, 3.2, 2.6, (n) => n) * 3);
    const lines = (
        filtered
            ? LOG_LINES.filter(([, , , message]) => /payment/i.test(message))
            : LOG_LINES.slice(3)
    ).slice(filtered ? 3 - arrived : 0);

    return (
        <AppShell active="Logs" crumbs={['Nordic Goods', 'Storefront', 'Logs']}>
            <PageHeader title="Logs" />
            <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>
                <Card style={{ width: 210, flexShrink: 0 }}>
                    <div
                        style={{
                            padding: '12px 16px 6px',
                            fontSize: 12,
                            letterSpacing: 0.8,
                            color: C.ink3,
                        }}
                    >
                        CHANNELS
                    </div>
                    {[
                        ['All channels', '1,204'],
                        ['default', '811'],
                        ['payments', '263'],
                        ['search', '130'],
                    ].map(([name, count], i) => (
                        <div
                            key={name}
                            style={{
                                display: 'flex',
                                padding: '9px 16px',
                                fontFamily: i ? MONO : FONT,
                                fontSize: 14,
                                background: i ? undefined : C.panel2,
                                boxShadow: i
                                    ? undefined
                                    : `inset 3px 0 0 ${C.amber}`,
                            }}
                        >
                            {name}
                            <span
                                style={{
                                    marginLeft: 'auto',
                                    fontFamily: MONO,
                                    fontSize: 12,
                                    color: C.ink3,
                                }}
                            >
                                {count}
                            </span>
                        </div>
                    ))}
                    <div
                        style={{
                            padding: '10px 16px 14px',
                            fontSize: 13,
                            color: C.amber,
                        }}
                    >
                        Manage channels
                    </div>
                </Card>
                <div style={{ flex: 1, minWidth: 0 }}>
                    <div
                        style={{
                            display: 'flex',
                            gap: 10,
                            alignItems: 'center',
                        }}
                    >
                        <span style={{ fontSize: 13, color: C.ink3 }}>
                            Minimum level
                        </span>
                        <span
                            style={{
                                display: 'inline-flex',
                                border: `1px solid ${C.line}`,
                                borderRadius: 7,
                                overflow: 'hidden',
                                fontSize: 13,
                            }}
                        >
                            {[
                                'debug',
                                'info',
                                'notice',
                                'warning',
                                'error',
                                'critical',
                            ].map((level, i) => (
                                <span
                                    key={level}
                                    style={{
                                        padding: '6px 10px',
                                        background: i ? undefined : C.panel2,
                                        color: i ? C.ink2 : C.ink,
                                        borderLeft: i
                                            ? `1px solid ${C.line}`
                                            : undefined,
                                    }}
                                >
                                    {level}
                                </span>
                            ))}
                        </span>
                        <Spot at={1.0} out={2.6} pad={2} style={{ flex: 1 }}>
                            <div
                                style={{
                                    padding: '6px 12px',
                                    border: `1px solid ${C.lineStrong}`,
                                    borderRadius: 7,
                                    fontSize: 14,
                                    color: typed ? C.ink : C.ink3,
                                    background: C.ground,
                                }}
                            >
                                {typed || 'Search messages'}
                                {typed && t < 2.6 ? (
                                    <span
                                        style={{
                                            display: 'inline-block',
                                            width: 2,
                                            height: 15,
                                            marginLeft: 2,
                                            background: C.amber,
                                            verticalAlign: 'middle',
                                        }}
                                    />
                                ) : null}
                            </div>
                        </Spot>
                        <span
                            style={{
                                display: 'inline-flex',
                                width: 120,
                                padding: '6px 12px',
                                border: `1px solid ${C.line}`,
                                borderRadius: 7,
                                fontSize: 13,
                            }}
                        >
                            All releases
                            <span style={{ marginLeft: 'auto', color: C.ink3 }}>
                                ⌄
                            </span>
                        </span>
                    </div>
                    <Card style={{ marginTop: 14 }}>
                        <THead
                            cols={[
                                ['Time', 120],
                                ['Level', 90],
                                ['Channel', 100],
                                ['Message', 'flex'],
                            ]}
                        />
                        {lines.map(([tone, level, channel, message], i) => (
                            <TRow key={message + i} first={i === 0}>
                                <Cell w={120} color={C.ink3}>
                                    03:{String(41 - i).padStart(2, '0')}:
                                    {String((i * 17 + 9) % 60).padStart(2, '0')}
                                </Cell>
                                <span style={colStyle(90)}>
                                    <Badge t={tone}>{level}</Badge>
                                </span>
                                <Cell w={100} color={C.ink2}>
                                    {channel}
                                </Cell>
                                <Cell w="flex" mono={false}>
                                    {message}
                                </Cell>
                            </TRow>
                        ))}
                    </Card>
                </div>
            </div>
        </AppShell>
    );
};

export const Logs = () => {
    const t = useTime();
    const cam = camera(t, [
        { t: 0, x: 720, y: 450, s: 1 },
        { t: 1.6, x: 880, y: 200, s: 1.35 },
        { t: 4.2, x: 860, y: 360, s: 1.25 },
        { t: 6.2, x: 760, y: 420, s: 1.05 },
    ]);

    return (
        <Feature
            side="left"
            duration={DURATION}
            kicker="Logs"
            title="Search your logs, or watch them *live*"
            subs={[
                ['From every app, host and environment.', 0.8],
                ['New lines come in at the top as they happen.', 3.4],
            ]}
        >
            <Camera {...cam}>
                <LogsPage />
            </Camera>
        </Feature>
    );
};

// ---- AI usage -----------------------------------------------------------------------

const MODELS = [
    { name: 'claude-sonnet-4-5', color: C.amber, seed: 1 },
    { name: 'gpt-5', color: C.info, seed: 2 },
    { name: 'claude-haiku-4-5', color: C.ok, seed: 3 },
];

const series = (seed: number, scale: number) =>
    Array.from(
        { length: 30 },
        (_, i) =>
            scale *
            (3 + Math.sin(i * 0.7 + seed) * 1.4 + ((i * seed * 7) % 5) * 0.35),
    );

const AiUsagePage = () => {
    const t = useTime();

    return (
        <AppShell
            active="Usage"
            crumbs={['Nordic Goods', 'Storefront', 'AI usage']}
            tabs={{ items: ['Usage', 'Budgets', 'Prices'], active: 'Usage' }}
        >
            <PageHeader
                title="AI usage"
                subtitle="Tokens and cost of the calls your apps made to AI models."
                aside={<Button>Find a key</Button>}
            />
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(5, 1fr)',
                    gap: 12,
                }}
            >
                {[
                    [
                        'Tokens',
                        `${countUp(t, 0.5, 1.6, 48.2).toFixed(1)}M`,
                        '41.6M in, 6.6M out · 18% more',
                        C.ink,
                    ],
                    [
                        'Cost to us',
                        `$${countUp(t, 0.5, 1.6, 412.8).toFixed(2)}`,
                        '21% more',
                        C.ink,
                    ],
                    [
                        'Cost to customers',
                        `$${countUp(t, 0.5, 1.6, 96.4).toFixed(2)}`,
                        '9% more',
                        C.ink,
                    ],
                    [
                        'Calls',
                        Math.round(countUp(t, 0.5, 1.6, 18240)).toLocaleString(
                            'en-US',
                        ),
                        '15% more',
                        C.ink,
                    ],
                    ['Error rate', '0.42%', '77 failed calls', C.warn],
                ].map(([label, value, note, color]) => (
                    <div
                        key={label}
                        style={{
                            background: C.panel,
                            border: `1px solid ${C.line}`,
                            borderRadius: 12,
                            padding: '16px 18px',
                        }}
                    >
                        <div
                            style={{
                                fontSize: 12,
                                letterSpacing: 1,
                                color: C.ink3,
                                textTransform: 'uppercase',
                            }}
                        >
                            {label}
                        </div>
                        <div
                            style={{
                                marginTop: 8,
                                fontFamily: MONO,
                                fontSize: 28,
                                color,
                            }}
                        >
                            {value}
                        </div>
                        <div
                            style={{
                                marginTop: 6,
                                fontSize: 13,
                                color: C.ink3,
                            }}
                        >
                            {note}
                        </div>
                    </div>
                ))}
            </div>
            <Card style={{ marginTop: 18 }}>
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        padding: '14px 18px',
                        borderBottom: `1px solid ${C.line}`,
                    }}
                >
                    <span style={{ fontWeight: 600, fontSize: 15 }}>
                        Cost and tokens by model
                    </span>
                    <span
                        style={{
                            marginLeft: 'auto',
                            display: 'flex',
                            gap: 16,
                            fontFamily: MONO,
                            fontSize: 13,
                            color: C.ink2,
                        }}
                    >
                        {MODELS.map((model) => (
                            <span
                                key={model.name}
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 6,
                                }}
                            >
                                <Dot
                                    size={9}
                                    t={
                                        model.color === C.amber
                                            ? 'amber'
                                            : model.color === C.info
                                              ? 'info'
                                              : 'ok'
                                    }
                                />
                                {model.name}
                            </span>
                        ))}
                    </span>
                </div>
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: '1fr 1fr',
                        gap: 24,
                        padding: '16px 18px',
                    }}
                >
                    {['Cost by model', 'Tokens by model'].map((title, c) => (
                        <div key={title}>
                            <div
                                style={{
                                    fontSize: 14,
                                    color: C.ink2,
                                    marginBottom: 10,
                                }}
                            >
                                {title}
                            </div>
                            <div style={{ position: 'relative', height: 170 }}>
                                {MODELS.map((model, m) => (
                                    <div
                                        key={model.name}
                                        style={{
                                            position: 'absolute',
                                            inset: 0,
                                        }}
                                    >
                                        <Line
                                            values={series(
                                                model.seed + c,
                                                1 - m * 0.25,
                                            )}
                                            max={6}
                                            at={0.9 + m * 0.15}
                                            width={540}
                                            height={170}
                                            color={model.color}
                                            strokeWidth={2}
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </Card>
        </AppShell>
    );
};

const BUDGET_COLS: Col[] = [
    ['Budget', 'flex'],
    ['Used', 220],
    ['Of', 220, 'right'],
    ['At this pace', 110, 'right'],
];

const AiBudgetsPage = () => {
    const t = useTime();
    const used = progress(t, SWAP + 0.4, 2) * 90;

    return (
        <AppShell
            active="Budgets"
            crumbs={['Nordic Goods', 'Storefront', 'AI usage', 'Budgets']}
            tabs={{ items: ['Usage', 'Budgets', 'Prices'], active: 'Budgets' }}
            period={false}
        >
            <PageHeader
                title="AI budgets"
                subtitle="How much the project, some keys, key owners or actors may use a day or a month."
                aside={<Button primary>Add budget</Button>}
            />
            <Card>
                <CardHead
                    title="Budgets"
                    hint="Alerts come from the AI budget rule under Alerts."
                />
                <THead cols={BUDGET_COLS} />
                {[
                    {
                        name: 'Monthly AI spend',
                        scope: 'The whole project · Us',
                        pct: used,
                        of: `$${((used / 100) * 500).toFixed(2)} / $500.00 a month`,
                        pace: '$668.40',
                        badge:
                            used > 70
                                ? (['On course to go over', 'warn'] as [
                                      string,
                                      Tone,
                                  ])
                                : null,
                        color: C.amber,
                    },
                    {
                        name: 'Support assistant',
                        scope: 'Actors · Anyone',
                        pct: 70,
                        of: '$4.63 / $6.60 a day',
                        pace: '$5.37',
                        badge: null,
                        color: C.ok,
                    },
                    {
                        name: 'Tenants 7 and 12 (BYOK)',
                        scope: 'Key owners · Anyone',
                        pct: 120,
                        of: '$16.03 / $13.36 a month',
                        pace: '$26.93',
                        badge: ['Over', 'err'] as [string, Tone],
                        color: C.err,
                    },
                ].map((budget, i) => {
                    const row = (
                        <TRow first={i === 0}>
                            <div style={colStyle('flex')}>
                                <div
                                    style={{
                                        display: 'flex',
                                        gap: 8,
                                        alignItems: 'center',
                                        fontSize: 15,
                                        fontWeight: 600,
                                    }}
                                >
                                    {budget.name}
                                    {budget.badge ? (
                                        <Badge t={budget.badge[1]}>
                                            {budget.badge[0]}
                                        </Badge>
                                    ) : null}
                                </div>
                                <div
                                    style={{
                                        marginTop: 3,
                                        fontSize: 13,
                                        color: C.ink3,
                                    }}
                                >
                                    {budget.scope}
                                </div>
                            </div>
                            <span
                                style={{
                                    ...colStyle(220),
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 12,
                                }}
                            >
                                <span
                                    style={{
                                        flex: 1,
                                        height: 6,
                                        borderRadius: 3,
                                        background: C.panel2,
                                    }}
                                >
                                    <i
                                        style={{
                                            display: 'block',
                                            height: 6,
                                            borderRadius: 3,
                                            width: `${Math.min(100, budget.pct)}%`,
                                            background: budget.color,
                                        }}
                                    />
                                </span>
                                <span
                                    style={{
                                        fontFamily: MONO,
                                        fontSize: 14,
                                        width: 44,
                                        textAlign: 'right',
                                    }}
                                >
                                    {Math.round(budget.pct)}%
                                </span>
                            </span>
                            <Cell w={220} right>
                                {budget.of}
                            </Cell>
                            <Cell w={110} right>
                                {budget.pace}
                            </Cell>
                        </TRow>
                    );

                    return i === 0 ? (
                        <Spot
                            key={budget.name}
                            at={4.6}
                            out={6.2}
                            label="Warns you before the bill does"
                            pad={0}
                        >
                            {row}
                        </Spot>
                    ) : (
                        <div key={budget.name}>{row}</div>
                    );
                })}
            </Card>
        </AppShell>
    );
};

export const AiUsage = () => {
    const t = useTime();
    const cam = camera(t, [
        { t: 0, x: 720, y: 450, s: 1 },
        { t: 2.0, x: 820, y: 230, s: 1.3 },
        { t: SWAP + 0.5, x: 720, y: 450, s: 1 },
        { t: 5.0, x: 820, y: 330, s: 1.35 },
    ]);

    return (
        <Feature
            duration={DURATION}
            kicker="AI usage"
            title="Know what your AI *costs*"
            subs={[
                ['Tokens and cost, by model, key and user.', 0.8],
                ['Budgets that warn you before the bill does.', 3.6],
            ]}
        >
            <Camera {...cam}>
                <PageSwap
                    at={SWAP}
                    before={<AiUsagePage />}
                    after={<AiBudgetsPage />}
                />
            </Camera>
        </Feature>
    );
};

// ---- Status screens -----------------------------------------------------------------

const TV_W = 1920;
const TV_H = 1080;

const qr = Array.from({ length: 29 * 29 }, (_, i) => {
    const x = i % 29;
    const y = Math.floor(i / 29);
    const finder = (a: number, b: number) =>
        x >= a && x < a + 7 && y >= b && y < b + 7;
    if (finder(0, 0) || finder(22, 0) || finder(0, 22)) {
        const lx = x % 22 === x ? x : x - 22;
        const ly = y % 22 === y ? y : y - 22;
        return (
            lx === 0 ||
            ly === 0 ||
            lx === 6 ||
            ly === 6 ||
            (lx >= 2 && lx <= 4 && ly >= 2 && ly <= 4)
        );
    }
    if (x >= 11 && x <= 17 && y >= 11 && y <= 17) {
        return false;
    }
    return (x * 7 + y * 13 + x * y) % 5 < 2;
});

const Pairing = () => (
    <div
        style={{
            width: TV_W,
            height: TV_H,
            background: C.ground,
            fontFamily: FONT,
            color: C.ink,
            position: 'relative',
        }}
    >
        <div
            style={{
                position: 'absolute',
                left: 96,
                top: 60,
                display: 'flex',
                alignItems: 'center',
                gap: 16,
                fontSize: 30,
                fontWeight: 600,
            }}
        >
            <Logo size={44} /> Lumberjack
        </div>
        <div style={{ position: 'absolute', left: 308, top: 250 }}>
            <div style={{ fontSize: 58, fontWeight: 600, lineHeight: 1.2 }}>
                Show a status
                <br />
                screen on this TV
            </div>
            <ol
                style={{
                    margin: '36px 0 0',
                    paddingLeft: 34,
                    fontSize: 28,
                    color: C.ink2,
                    lineHeight: 2,
                }}
            >
                <li>
                    Scan the code with your phone, or open
                    <br />
                    <span style={{ fontFamily: MONO, color: C.amber }}>
                        lumberjack.nordicgoods.com/pair
                    </span>
                </li>
                <li>Enter this code if asked</li>
                <li>Pick the screen and confirm</li>
            </ol>
            <div
                style={{
                    display: 'flex',
                    gap: 14,
                    marginTop: 36,
                    alignItems: 'center',
                }}
            >
                {'K7Q-4ZP'.split('').map((ch, i) =>
                    ch === '-' ? (
                        <span key={i} style={{ fontSize: 60, color: C.ink2 }}>
                            -
                        </span>
                    ) : (
                        <span
                            key={i}
                            style={{
                                width: 96,
                                height: 116,
                                display: 'grid',
                                placeItems: 'center',
                                borderRadius: 12,
                                border: `1px solid ${C.lineStrong}`,
                                background: C.panel,
                                fontFamily: MONO,
                                fontSize: 72,
                                fontWeight: 700,
                            }}
                        >
                            {ch}
                        </span>
                    ),
                )}
            </div>
        </div>
        <div
            style={{
                position: 'absolute',
                left: 1192,
                top: 312,
                width: 420,
                height: 420,
                padding: 22,
                borderRadius: 16,
                background: C.panel,
                border: `1px solid ${C.lineStrong}`,
            }}
        >
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(29, 1fr)',
                    gap: 1,
                    position: 'relative',
                }}
            >
                {qr.map((on, i) => (
                    <i
                        key={i}
                        style={{
                            aspectRatio: '1',
                            borderRadius: 2,
                            background: on ? C.amber : 'transparent',
                            display: 'block',
                        }}
                    />
                ))}
                <div
                    style={{
                        position: 'absolute',
                        left: '50%',
                        top: '50%',
                        transform: 'translate(-50%, -50%)',
                    }}
                >
                    <Logo size={70} box={false} />
                </div>
            </div>
        </div>
    </div>
);

const Tile = ({
    title,
    period,
    children,
    tone,
}: {
    title: string;
    period: string;
    children: ReactNode;
    tone?: 'ok' | 'err';
}) => (
    <div
        style={{
            position: 'relative',
            background: tone === 'err' ? '#2a1715' : C.panel,
            border: `2px solid ${tone === 'ok' ? C.okLine : tone === 'err' ? C.err : C.line}`,
            borderRadius: 12,
            padding: '20px 24px',
            overflow: 'hidden',
        }}
    >
        <div style={{ fontSize: 24, fontWeight: 600 }}>{title}</div>
        <span
            style={{
                position: 'absolute',
                right: 20,
                top: 16,
                fontSize: 22,
                color: C.ink3,
            }}
        >
            {period}
        </span>
        {children}
    </div>
);

const Kiosk = () => {
    const t = useTime();
    const requests = Math.round(33100 + countUp(t, 3.2, 3, 245));

    return (
        <div
            style={{
                width: TV_W,
                height: TV_H,
                background: C.ground,
                fontFamily: FONT,
                color: C.ink,
                padding: '40px 46px',
                display: 'flex',
                flexDirection: 'column',
                gap: 18,
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 18 }}>
                <Logo size={46} />
                <div>
                    <div style={{ fontSize: 34, fontWeight: 600 }}>
                        Storefront
                    </div>
                    <div style={{ fontSize: 19, color: C.ink2 }}>
                        Nordic Goods · Storefront · production · Last 24 hours
                    </div>
                </div>
                <span
                    style={{
                        marginLeft: 'auto',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        fontSize: 19,
                        color: C.ok,
                    }}
                >
                    <Dot t="ok" size={11} /> Live
                </span>
                <span
                    style={{
                        fontFamily: MONO,
                        fontSize: 52,
                        fontWeight: 700,
                        marginLeft: 26,
                    }}
                >
                    04:12
                </span>
            </div>
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(4, 1fr)',
                    gap: 18,
                    height: 220,
                }}
            >
                <Tile title="Requests" period="24h">
                    <div
                        style={{
                            fontFamily: MONO,
                            fontSize: 64,
                            fontWeight: 700,
                        }}
                    >
                        {requests.toLocaleString('en-US')}
                    </div>
                    <div style={{ fontSize: 17, color: C.ok }}>
                        ▼ 15% vs the previous period
                    </div>
                </Tile>
                <Tile title="Error rate" period="24h" tone="ok">
                    <div
                        style={{
                            fontFamily: MONO,
                            fontSize: 64,
                            fontWeight: 700,
                            color: C.ok,
                        }}
                    >
                        0.01%
                    </div>
                </Tile>
                <Tile title="Open issues" period="now">
                    <div
                        style={{
                            fontFamily: MONO,
                            fontSize: 64,
                            fontWeight: 700,
                            color: C.amber,
                        }}
                    >
                        6
                    </div>
                </Tile>
                <Tile title="Current release" period="now">
                    <div
                        style={{
                            fontFamily: MONO,
                            fontSize: 64,
                            fontWeight: 700,
                        }}
                    >
                        v2.16.3
                    </div>
                    <div style={{ fontSize: 17, color: C.ink3 }}>
                        out for 22 min
                    </div>
                </Tile>
            </div>
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: '1fr 1fr',
                    gap: 18,
                    flex: 1,
                }}
            >
                <Tile title="Requests" period="24h">
                    <div style={{ marginTop: 18 }}>
                        <Line
                            values={[
                                40, 30, 22, 18, 16, 15, 18, 26, 40, 58, 70, 80,
                                86, 90, 84, 80, 76, 74, 78, 82, 86, 80, 70, 60,
                            ]}
                            at={3.1}
                            width={820}
                            height={330}
                            color={C.info}
                            strokeWidth={3}
                        />
                    </div>
                </Tile>
                <Tile title="Health checks" period="now" tone="ok">
                    <div
                        style={{
                            marginTop: 16,
                            display: 'flex',
                            flexDirection: 'column',
                        }}
                    >
                        {[
                            'Storefront',
                            'Payments API',
                            'Certificate',
                            'Database',
                            'shop.example.com',
                            'Nightly backup',
                        ].map((check) => (
                            <div
                                key={check}
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 14,
                                    padding: '12px 0',
                                    borderBottom: `1px solid ${C.line}`,
                                    fontSize: 24,
                                }}
                            >
                                <span style={{ color: C.ok }}>✓</span>
                                {check}
                                <span
                                    style={{
                                        marginLeft: 'auto',
                                        color: C.ok,
                                        fontSize: 20,
                                    }}
                                >
                                    OK
                                </span>
                            </div>
                        ))}
                    </div>
                </Tile>
            </div>
        </div>
    );
};

export const Screens = () => {
    const t = useTime();
    const enter = progress(t, 0.05, 1.2);
    const leave = progress(t, DURATION - 0.9, 0.9, easeInOut);
    const live = progress(t, 2.9, 0.6);

    return (
        <AbsoluteFill>
            <div style={{ position: 'absolute', top: 300, left: 110 }}>
                <Headline
                    kicker="Status screens"
                    title="Put it on the *wall*"
                    at={0.25}
                    out={DURATION - 0.95}
                    width={520}
                    size={68}
                    subs={[
                        ['Pair any TV with a code.', 0.8],
                        ['Your own screen of widgets, live.', 3.2],
                    ]}
                />
            </div>
            <div
                style={{
                    position: 'absolute',
                    left: 700,
                    top: 180,
                    width: 1120,
                    opacity: enter * (1 - leave),
                    transform: `translateY(${(1 - enter) * 60}px) scale(${0.97 + enter * 0.03})`,
                }}
            >
                <div
                    style={{
                        padding: 16,
                        borderRadius: 22,
                        background: '#050607',
                        border: '1px solid #2a2f35',
                        boxShadow:
                            '0 60px 140px rgba(0,0,0,0.7), 0 0 140px rgba(240,169,59,0.1)',
                    }}
                >
                    <div
                        style={{
                            position: 'relative',
                            width: 1088,
                            height: 612,
                            overflow: 'hidden',
                            borderRadius: 8,
                        }}
                    >
                        <div
                            style={{
                                position: 'absolute',
                                transform: `scale(${1088 / TV_W})`,
                                transformOrigin: '0 0',
                                opacity: 1 - live,
                            }}
                        >
                            <Pairing />
                        </div>
                        <div
                            style={{
                                position: 'absolute',
                                transform: `scale(${1088 / TV_W})`,
                                transformOrigin: '0 0',
                                opacity: live,
                            }}
                        >
                            <Kiosk />
                        </div>
                    </div>
                </div>
                <div
                    style={{
                        width: 260,
                        height: 14,
                        margin: '0 auto',
                        background: '#16191c',
                        borderRadius: '0 0 8px 8px',
                    }}
                />
            </div>
        </AbsoluteFill>
    );
};
