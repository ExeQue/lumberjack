import { camera, progress, sec, useTime } from '../anim';
import { C, MONO } from '../theme';
import {
    AppShell,
    Badge,
    Button,
    Camera,
    Card,
    CardHead,
    colStyle,
    PageHeader,
    Spot,
    THead,
    type Col,
} from '../ui';
import { Feature, PageSwap } from './feature';

const DURATION = 12;
export const HEALTH = sec(DURATION);

const DOWN = 2.4;
const SWAP = 5.2;

const CHECKS = [
    {
        name: 'Storefront',
        target: 'http  https://shop.example.com',
        latency: '142 ms',
        uptime: '100%',
    },
    {
        name: 'Payments API',
        target: 'http  https://api.payments.example/health',
        latency: '302 ms',
        uptime: '99.2%',
        fails: true,
    },
    {
        name: 'Certificate',
        target: 'ssl  shop.example.com',
        latency: '61 days',
        uptime: '100%',
    },
    {
        name: 'Database',
        target: 'tcp  db.internal:5432',
        latency: '3 ms',
        uptime: '100%',
    },
    {
        name: 'shop.example.com',
        target: 'dns  A record',
        latency: '12 ms',
        uptime: '100%',
    },
    {
        name: 'Nightly backup',
        target: 'heartbeat  every day',
        latency: '-',
        uptime: '100%',
    },
];

const COLS: Col[] = [
    ['Check', 'flex'],
    ['Status', 90],
    ['Last result', 150],
    ['Latency', 90, 'right'],
    ['Uptime', 80, 'right'],
    ['History', 300],
];

const BLOCKS = 26;

const HealthPage = () => {
    const t = useTime();
    const failed = Math.floor(progress(t, DOWN, 1, (n) => n) * 3);
    const isDown = t > DOWN + 0.4;

    return (
        <AppShell
            active="Health"
            crumbs={['Nordic Goods', 'Storefront', 'Health']}
        >
            <PageHeader
                title="Health"
                subtitle="6 health checks."
                aside={<Button primary>Add check</Button>}
            />
            <div
                style={{
                    height: isDown ? 54 : 0,
                    opacity: progress(t, DOWN + 0.4, 0.4),
                    overflow: 'hidden',
                    marginBottom: isDown ? 20 : 0,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 12,
                    padding: '0 18px',
                    borderRadius: 10,
                    border: `1px solid ${C.errLine}`,
                    background: C.errBg,
                    color: C.ink,
                    fontWeight: 600,
                    fontSize: 16,
                }}
            >
                <span style={{ color: C.err }}>⚠</span> 1 down, 0 degraded.
            </div>
            <Card>
                <CardHead
                    title="Run by Lumberjack"
                    hint="HTTP, SSL, TCP, DNS and heartbeat checks this server runs on their interval."
                />
                <THead cols={COLS} />
                {CHECKS.map((check) => {
                    const down = check.fails && isDown;
                    const row = (
                        <div
                            style={{
                                display: 'flex',
                                gap: 14,
                                alignItems: 'center',
                                padding: '12px 18px',
                                borderTop: `1px solid ${C.line}`,
                            }}
                        >
                            <div style={colStyle('flex')}>
                                <div style={{ fontSize: 15 }}>{check.name}</div>
                                <div
                                    style={{
                                        marginTop: 3,
                                        fontFamily: MONO,
                                        fontSize: 13,
                                        color: C.ink3,
                                        whiteSpace: 'pre',
                                    }}
                                >
                                    {check.target}
                                </div>
                            </div>
                            <span style={colStyle(90)}>
                                <Badge t={down ? 'err' : 'ok'}>
                                    {down ? 'Down' : 'OK'}
                                </Badge>
                            </span>
                            <span
                                style={{
                                    ...colStyle(150),
                                    fontSize: 14,
                                    color: down ? C.err : C.ink2,
                                }}
                            >
                                {down ? '503 · just now' : 'just now'}
                            </span>
                            <span
                                style={{
                                    ...colStyle(90),
                                    textAlign: 'right',
                                    fontFamily: MONO,
                                    fontSize: 14,
                                }}
                            >
                                {down ? '-' : check.latency}
                            </span>
                            <span
                                style={{
                                    ...colStyle(80),
                                    textAlign: 'right',
                                    fontFamily: MONO,
                                    fontSize: 14,
                                    color: C.ink2,
                                }}
                            >
                                {check.uptime}
                            </span>
                            <span
                                style={{
                                    ...colStyle(300),
                                    display: 'flex',
                                    gap: 3,
                                }}
                            >
                                {Array.from({ length: BLOCKS }, (_, i) => (
                                    <i
                                        key={i}
                                        style={{
                                            flex: 1,
                                            height: 18,
                                            borderRadius: 2,
                                            background:
                                                check.fails &&
                                                i >= BLOCKS - failed
                                                    ? C.err
                                                    : C.ok,
                                        }}
                                    />
                                ))}
                            </span>
                        </div>
                    );

                    return check.fails ? (
                        <Spot
                            key={check.name}
                            at={3.2}
                            out={5.0}
                            label="Down since 03:01"
                            pad={0}
                        >
                            {row}
                        </Spot>
                    ) : (
                        <div key={check.name}>{row}</div>
                    );
                })}
            </Card>
        </AppShell>
    );
};

const CHANNELS = [
    { kind: 'Slack', name: '#ops', target: 'hooks.slack.com/····' },
    {
        kind: 'Microsoft Teams',
        name: 'Storefront on-call',
        target: 'outlook.office.com/····',
    },
    {
        kind: 'Discord',
        name: '#shop-alerts',
        target: 'https://discord.com/····',
    },
    {
        kind: 'Mail',
        name: 'On-call inbox',
        target: 'oncall@nordicgoods.example',
    },
];

const AlertsPage = () => {
    const t = useTime();

    return (
        <AppShell
            active="Alerts"
            crumbs={['Nordic Goods', 'Alerts']}
            period={false}
        >
            <PageHeader title="Alerts" />
            <Spot at={6.6} out={10.4} label="Health check down in Storefront">
                <Card>
                    <CardHead
                        title="Channels"
                        hint="Set up once for the team and reused in every project's rules."
                    />
                    <div
                        style={{
                            padding: '12px 14px',
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 10,
                        }}
                    >
                        {CHANNELS.map((channel, i) => {
                            const sent = t > SWAP + 1.2 + i * 0.3;

                            return (
                                <div
                                    key={channel.name}
                                    style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 12,
                                        padding: '10px 14px',
                                        border: `1px solid ${C.line}`,
                                        borderRadius: 10,
                                    }}
                                >
                                    <Badge>{channel.kind}</Badge>
                                    <span
                                        style={{
                                            fontWeight: 600,
                                            fontSize: 15,
                                        }}
                                    >
                                        {channel.name}
                                    </span>
                                    <span
                                        style={{
                                            fontFamily: MONO,
                                            fontSize: 13,
                                            color: C.ink3,
                                        }}
                                    >
                                        {channel.target}
                                    </span>
                                    <span
                                        style={{
                                            marginLeft: 'auto',
                                            display: 'flex',
                                            gap: 8,
                                            alignItems: 'center',
                                        }}
                                    >
                                        <span
                                            style={{ opacity: sent ? 1 : 0.45 }}
                                        >
                                            <Badge t="ok">
                                                {sent
                                                    ? 'Delivered just now'
                                                    : 'Delivered 6 days ago'}
                                            </Badge>
                                        </span>
                                        <Button>Edit</Button>
                                        <Button>Send test</Button>
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </Card>
            </Spot>
            <Card style={{ marginTop: 18 }}>
                <CardHead
                    title="Rules"
                    hint="Every project uses these unless it customizes a rule in its settings."
                />
                <div
                    style={{
                        padding: '14px 18px',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 14,
                    }}
                >
                    <span
                        style={{
                            width: 40,
                            height: 22,
                            borderRadius: 11,
                            background: C.amber,
                            position: 'relative',
                            display: 'block',
                            flexShrink: 0,
                        }}
                    >
                        <i
                            style={{
                                position: 'absolute',
                                right: 3,
                                top: 3,
                                width: 16,
                                height: 16,
                                borderRadius: 8,
                                background: '#fff',
                                display: 'block',
                            }}
                        />
                    </span>
                    <div>
                        <div style={{ fontWeight: 600, fontSize: 15 }}>
                            Health check down{' '}
                            <span
                                style={{
                                    fontWeight: 400,
                                    color: C.ink3,
                                    fontSize: 13,
                                }}
                            >
                                On
                            </span>
                        </div>
                        <div
                            style={{
                                marginTop: 3,
                                fontSize: 14,
                                color: C.ink2,
                            }}
                        >
                            A health check that keeps failing past its
                            threshold, including one that was degraded first.
                        </div>
                    </div>
                </div>
            </Card>
        </AppShell>
    );
};

export const Health = () => {
    const t = useTime();
    const cam = camera(t, [
        { t: 0, x: 720, y: 450, s: 1 },
        { t: 3.3, x: 700, y: 380, s: 1.4 },
        { t: SWAP + 0.4, x: 720, y: 450, s: 1 },
        { t: 7.2, x: 760, y: 280, s: 1.35 },
        { t: 10.8, x: 720, y: 450, s: 1 },
    ]);

    return (
        <Feature
            duration={DURATION}
            kicker="Health & alerts"
            title="Know before your *customers* do"
            subs={[
                ['HTTP, SSL, TCP, DNS and heartbeat checks.', 0.9],
                ['The Payments API went down at 03:01.', 3.0],
                ['The alert goes out on every channel you set up.', 5.8],
                [
                    'Slack, Teams, Discord, Telegram, ntfy, Pushover, mail or a webhook.',
                    8.6,
                ],
            ]}
        >
            <Camera {...cam}>
                <PageSwap
                    at={SWAP}
                    before={<HealthPage />}
                    after={<AlertsPage />}
                />
            </Camera>
        </Feature>
    );
};
