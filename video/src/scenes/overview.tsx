import { camera, countUp, progress, sec, useTime } from '../anim';
import { C, MONO } from '../theme';
import {
    PageHeader,
    AppShell,
    Badge,
    Bars,
    Camera,
    Card,
    Dot,
    Line,
    Spot,
    Stat,
} from '../ui';
import { Feature } from './feature';

const DURATION = 12;
export const OVERVIEW = sec(DURATION);

const REQUESTS = [
    1480, 1390, 980, 620, 410, 330, 300, 340, 520, 880, 1310, 1720, 2050, 2280,
    2470, 2390, 2240, 2010, 1890, 1960, 2140, 2290, 2010, 1680,
];
const EXCEPTIONS = [
    3, 2, 2, 1, 2, 1, 1, 1, 2, 3, 2, 2, 1, 2, 2, 1, 2, 2, 1, 2, 78, 41, 2, 1,
];
const P95 = [
    210, 205, 220, 214, 198, 230, 226, 212, 240, 236, 218, 222, 230, 214, 226,
    210, 230, 236, 220, 228, 5320, 2900, 260, 211,
];

const CHECKS = [
    'Storefront',
    'Certificate',
    'Database: PostgreSQL',
    'Payments API',
    'Nightly backup',
    'Queue: Redis',
    'shop.example.com',
];

const OverviewPage = () => {
    const t = useTime();
    const requests = countUp(t, 0.8, 1.6, 33.3).toFixed(1);
    const p95 = Math.round(countUp(t, 0.9, 1.6, 211));
    const issues = Math.round(countUp(t, 1.0, 1.2, 6));

    return (
        <AppShell
            active="Overview"
            crumbs={['Nordic Goods', 'Storefront', 'Overview']}
        >
            <PageHeader
                title="Overview"
                subtitle={<Badge>Estimated from a 5.00% sample</Badge>}
            />
            <Spot
                at={2.6}
                out={4.6}
                label="The numbers that matter, for every project"
            >
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(6, 1fr)',
                        gap: 12,
                    }}
                >
                    <Stat
                        label="Requests"
                        value={`${requests}K`}
                        note="33,345 requests in the period · 15% less than the period before"
                    />
                    <Stat
                        label="Error rate"
                        value="0.01%"
                        note="149 exceptions recorded, 5xx share of requests"
                    />
                    <Stat
                        label="P95 response time"
                        value={`${p95} ms`}
                        note="p50 46 ms, p99 1.69 s"
                    />
                    <Stat
                        label="Failed jobs"
                        value="0"
                        note="0% of 1367 job runs, 0 stuck"
                    />
                    <Stat
                        label="Open issues"
                        value={issues}
                        color={C.amber}
                        note="All environments, regardless of period"
                    />
                    <Stat
                        label="Health checks"
                        value={
                            <span style={{ fontSize: 17 }}>
                                Every check passes
                            </span>
                        }
                        color={C.ok}
                        note="0 down, 0 degraded, 0 stale"
                    />
                </div>
            </Spot>
            <Card
                title="Health checks"
                aside="Open Health"
                style={{ marginTop: 16 }}
            >
                <div
                    style={{
                        display: 'flex',
                        flexWrap: 'wrap',
                        gap: 8,
                        padding: '14px 18px',
                    }}
                >
                    {CHECKS.map((check, i) => {
                        const fixed = check === 'Payments API';
                        const green = !fixed || t > 1.6;

                        return (
                            <span
                                key={check}
                                style={{
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: 8,
                                    padding: '5px 12px',
                                    borderRadius: 16,
                                    border: `1px solid ${green ? C.line : C.errLine}`,
                                    background: green ? C.panel2 : C.errBg,
                                    fontFamily: MONO,
                                    fontSize: 13,
                                    opacity: progress(t, 0.6 + i * 0.06, 0.4),
                                }}
                            >
                                <Dot t={green ? 'ok' : 'err'} />
                                {check}
                            </span>
                        );
                    })}
                </div>
            </Card>
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: '1fr 1fr',
                    gap: 16,
                    marginTop: 16,
                }}
            >
                <Card title="Requests over time" aside="Peak 2,470, 02:00 PM">
                    <div style={{ padding: '16px 18px' }}>
                        <Bars values={REQUESTS} at={1.2} height={150} />
                    </div>
                </Card>
                <Spot at={5.8} out={8.3} label="03:00, and the fix after it">
                    <Card
                        title="Exceptions over time"
                        aside="Peak 78, 03:00 AM"
                    >
                        <div style={{ padding: '16px 18px' }}>
                            <Bars
                                values={EXCEPTIONS}
                                at={1.4}
                                height={150}
                                color={C.err}
                            />
                        </div>
                    </Card>
                </Spot>
            </div>
            <Card
                title="Response times"
                aside="— p95  — p50"
                style={{ marginTop: 16 }}
            >
                <div style={{ padding: '16px 18px' }}>
                    <Line values={P95} at={1.8} width={1110} height={120} />
                </div>
            </Card>
        </AppShell>
    );
};

export const Overview = () => {
    const t = useTime();
    const cam = camera(t, [
        { t: 0, x: 720, y: 450, s: 1 },
        { t: 3.0, x: 820, y: 190, s: 1.35 },
        { t: 6.0, x: 1080, y: 480, s: 1.45 },
        { t: 9.4, x: 760, y: 640, s: 1.2 },
        { t: 11.4, x: 720, y: 450, s: 1 },
    ]);

    return (
        <Feature
            side="left"
            duration={DURATION}
            kicker="Overview"
            title="Every app, at a *glance*"
            subs={[
                ['An hour later: fixed, deployed, every check green.', 0.9],
                ['Requests, errors, p95, jobs, issues and health.', 3.2],
                ['The spike at 03:00, and the fix after it.', 5.6],
                ['One place for every project you run.', 8.4],
            ]}
        >
            <Camera {...cam} height={1000}>
                <OverviewPage />
            </Camera>
        </Feature>
    );
};
