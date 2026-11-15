import { Head, Link, router, usePoll } from '@inertiajs/react';
import { HostChart } from '@/components/lj/host-charts';
import { TopBar } from '@/components/lj/top-bar';
import {
    Card,
    CardHeader,
    EmptyState,
    Mono,
    numClass,
    PageTitle,
    Segmented,
    StatCard,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatBytes,
    formatNumber,
    formatPercent,
    formatRelative,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { retention, system } from '@/routes/admin';

type SystemPeriod = '1h' | '24h' | '7d';

type Point = { t: string; v: number | null };

type Latest = {
    sampled_at: string;
    cpu_load_1m: number | null;
    cpu_cores: number | null;
    memory_used_bytes: number | null;
    memory_total_bytes: number | null;
    disk_used_bytes: number | null;
    disk_total_bytes: number | null;
};

type Props = {
    period: SystemPeriod;
    latest: Latest | null;
    charts: { cpu: Point[]; memory: Point[]; disk: Point[] };
    database: {
        bytes: number;
        tables: {
            table: string;
            label: string | null;
            bytes: number;
            rows: number;
        }[];
    };
    queues:
        | {
              name: string;
              length: number;
              wait_seconds: number;
              processes: number;
          }[]
        | null;
    redis: { used_bytes: number; max_bytes: number | null } | null;
};

const PERIODS: SystemPeriod[] = ['1h', '24h', '7d'];

// A sampler that missed five minutes is not running.
const STALE_AFTER_MS = 5 * 60 * 1000;

const share = (used: number | null, total: number | null) =>
    used !== null && total !== null && total > 0 ? used / total : null;

const toneAt = (value: number | null, warn: number, err: number) =>
    value === null
        ? undefined
        : value >= err
          ? ('err' as const)
          : value >= warn
            ? ('warn' as const)
            : undefined;

export default function AdminSystem({
    period,
    latest,
    charts,
    database,
    queues,
    redis,
}: Props) {
    const t = useT();
    usePoll(60_000, {
        only: ['latest', 'charts', 'database', 'queues', 'redis'],
    });

    const cpu = share(latest?.cpu_load_1m ?? null, latest?.cpu_cores ?? null);
    const memory = share(
        latest?.memory_used_bytes ?? null,
        latest?.memory_total_bytes ?? null,
    );
    const disk = share(
        latest?.disk_used_bytes ?? null,
        latest?.disk_total_bytes ?? null,
    );
    const redisShare = share(
        redis?.used_bytes ?? null,
        redis?.max_bytes ?? null,
    );
    const stale =
        latest !== null &&
        Date.now() - new Date(latest.sampled_at).getTime() > STALE_AFTER_MS;
    const percent = (value: number) => formatPercent(value, 0);
    const usedOf = (used: number | null, total: number | null) =>
        used !== null && total !== null
            ? t('admin.system.used_of', {
                  used: formatBytes(used),
                  total: formatBytes(total),
              })
            : undefined;
    const series = (points: Point[]) => [
        { name: t('admin.system.series'), points },
    ];

    return (
        <>
            <Head title={t('admin.system.title')} />
            <TopBar
                crumbs={[
                    { label: t('admin.nav.section') },
                    { label: t('admin.system.title') },
                ]}
            />
            <PageBody>
                <PageTitle
                    title={t('admin.system.title')}
                    description={t('admin.system.description')}
                    actions={
                        <Segmented
                            label={t('admin.system.period')}
                            value={period}
                            options={PERIODS.map((value) => ({
                                value,
                                label: t(`admin.system.periods.${value}`),
                            }))}
                            onChange={(value) =>
                                router.get(
                                    system().url,
                                    { period: value },
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                        replace: true,
                                    },
                                )
                            }
                        />
                    }
                />

                {latest === null ? (
                    <Card>
                        <EmptyState title={t('admin.system.never_sampled')}>
                            {t('admin.system.never_sampled_body')}
                        </EmptyState>
                    </Card>
                ) : (
                    <p
                        role={stale ? 'alert' : undefined}
                        className={cn(
                            'text-xs',
                            stale ? 'font-medium text-err' : 'text-ink-3',
                        )}
                    >
                        {t(
                            stale
                                ? 'admin.system.stale'
                                : 'admin.system.sampled',
                            { when: formatRelative(latest.sampled_at) },
                        )}
                    </p>
                )}

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <StatCard
                        label={t('admin.system.cpu')}
                        value={
                            cpu === null
                                ? t('admin.system.unknown')
                                : percent(cpu)
                        }
                        tone={toneAt(cpu, 0.7, 0.9)}
                        note={
                            latest?.cpu_load_1m != null &&
                            latest.cpu_cores != null
                                ? t('admin.system.cpu_note', {
                                      load: formatNumber(latest.cpu_load_1m, 2),
                                      cores: formatNumber(latest.cpu_cores),
                                  })
                                : undefined
                        }
                    />
                    <StatCard
                        label={t('admin.system.memory')}
                        value={
                            memory === null
                                ? t('admin.system.unknown')
                                : percent(memory)
                        }
                        tone={toneAt(memory, 0.8, 0.9)}
                        note={usedOf(
                            latest?.memory_used_bytes ?? null,
                            latest?.memory_total_bytes ?? null,
                        )}
                    />
                    <StatCard
                        label={t('admin.system.disk')}
                        value={
                            disk === null
                                ? t('admin.system.unknown')
                                : percent(disk)
                        }
                        tone={toneAt(disk, 0.8, 0.9)}
                        note={usedOf(
                            latest?.disk_used_bytes ?? null,
                            latest?.disk_total_bytes ?? null,
                        )}
                    />
                    <StatCard
                        label={t('admin.system.database')}
                        value={formatBytes(database.bytes)}
                    />
                    <StatCard
                        label={t('admin.system.redis')}
                        value={
                            redis === null
                                ? t('admin.system.unavailable')
                                : formatBytes(redis.used_bytes)
                        }
                        tone={
                            redis === null
                                ? 'err'
                                : toneAt(redisShare, 0.8, 0.9)
                        }
                        note={
                            redis === null
                                ? undefined
                                : redis.max_bytes === null
                                  ? t('admin.system.redis_no_limit')
                                  : t('admin.system.redis_of', {
                                        max: formatBytes(redis.max_bytes),
                                    })
                        }
                    />
                </div>

                <Card aria-labelledby="system-charts">
                    <CardHeader
                        id="system-charts"
                        title={t('admin.system.charts')}
                    />
                    <div className="grid gap-4 p-4 lg:grid-cols-3">
                        {(
                            [
                                ['cpu', 'var(--lj-info)'],
                                ['memory', 'var(--lj-amber)'],
                                ['disk', 'var(--lj-ok)'],
                            ] as const
                        ).map(([figure, color]) => (
                            <HostChart
                                key={figure}
                                title={t(`admin.system.${figure}`)}
                                series={series(charts[figure])}
                                period={period}
                                colorOf={() => color}
                                format={percent}
                            />
                        ))}
                    </div>
                </Card>

                <div className="grid gap-3 xl:grid-cols-2">
                    <Card aria-labelledby="system-queues">
                        <CardHeader
                            id="system-queues"
                            title={t('admin.system.queues.title')}
                            description={t('admin.system.queues.description')}
                        />
                        {queues === null || queues.length === 0 ? (
                            <p
                                role={queues === null ? 'alert' : undefined}
                                className={cn(
                                    'p-4 text-[13px]',
                                    queues === null ? 'text-err' : 'text-ink-2',
                                )}
                            >
                                {t(
                                    queues === null
                                        ? 'admin.system.queues.unavailable'
                                        : 'admin.system.queues.empty',
                                )}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className={tableClass}>
                                    <thead>
                                        <tr>
                                            <th className={thClass}>
                                                {t('admin.system.queues.queue')}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'text-right',
                                                )}
                                            >
                                                {t(
                                                    'admin.system.queues.waiting',
                                                )}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'text-right',
                                                )}
                                            >
                                                {t('admin.system.queues.wait')}
                                            </th>
                                            <th
                                                className={cn(
                                                    thClass,
                                                    'text-right',
                                                )}
                                            >
                                                {t(
                                                    'admin.system.queues.processes',
                                                )}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {queues.map((queue) => (
                                            <tr key={queue.name}>
                                                <td className={tdClass}>
                                                    <Mono>{queue.name}</Mono>
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        numClass,
                                                    )}
                                                >
                                                    {formatNumber(queue.length)}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        numClass,
                                                    )}
                                                >
                                                    {t(
                                                        'admin.system.queues.seconds',
                                                        {
                                                            count: formatNumber(
                                                                queue.wait_seconds,
                                                            ),
                                                        },
                                                    )}
                                                </td>
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        numClass,
                                                    )}
                                                >
                                                    {formatNumber(
                                                        queue.processes,
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    <Card aria-labelledby="system-tables">
                        <CardHeader
                            id="system-tables"
                            title={t('admin.system.tables.title')}
                            description={t('admin.system.tables.description')}
                            aside={
                                <Link
                                    href={retention().url}
                                    className="text-xs text-info hover:underline"
                                >
                                    {t('admin.system.tables.retention')}
                                </Link>
                            }
                        />
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('admin.system.tables.table')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('admin.system.tables.size')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('admin.system.tables.rows')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {database.tables.map((table) => (
                                        <tr key={table.table}>
                                            <td className={tdClass}>
                                                {table.label ?? (
                                                    <Mono>{table.table}</Mono>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {formatBytes(table.bytes)}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {formatNumber(table.rows)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                </div>
            </PageBody>
        </>
    );
}
