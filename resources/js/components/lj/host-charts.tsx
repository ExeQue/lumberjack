import { useState } from 'react';
import { curve } from '@/components/lj/screen/lib';
import {
    bucketLabel,
    Card,
    CardHeader,
    Label,
    Select,
} from '@/components/lj/ui';
import { formatDuration, formatNumber, formatPercent } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Period } from '@/types/lumberjack';

type HostSeries = {
    name: string;
    points: { t: string; v: number | null }[];
};

export type HostCharts = {
    cpu_load: HostSeries[];
    memory_used: HostSeries[];
    disk_used: HostSeries[];
    /** Milliseconds. */
    p95: HostSeries[];
    occurrences: HostSeries[];
    job_failures: HostSeries[];
};

// ponytail: five colors repeat from the sixth host on; the hover and the focus tell them apart.
const COLORS = [
    'var(--lj-info)',
    'var(--lj-amber)',
    'var(--lj-ok)',
    'var(--lj-err)',
    'var(--lj-ink-3)',
];

const WIDTH = 400;
const HEIGHT = 120;

/**
 * One line with an area per host. A dot marks where a line starts and stops, and a
 * bucket no host reported is shaded, so a short line reads as "little data".
 */
function HostChart({
    title,
    series,
    period,
    colorOf,
    format,
    whole = false,
}: {
    title: string;
    series: HostSeries[];
    period: Period;
    colorOf: (host: string) => string;
    format: (value: number) => string;
    /** Whole counts: an even top, so the middle of the axis is a whole number too. */
    whole?: boolean;
}) {
    const t = useT();
    const buckets = series[0]?.points ?? [];
    const values = series.flatMap((s) =>
        s.points.flatMap((p) => (p.v === null ? [] : [p.v])),
    );
    // The axis tops out at 1 at least: 100% of a share, one exception or job, one ms.
    const peak = Math.max(1, ...values);
    const max = whole ? Math.ceil(peak / 2) * 2 : peak;
    const x = (i: number) => (i / Math.max(1, buckets.length - 1)) * WIDTH;
    const y = (v: number) => HEIGHT - (v / max) * (HEIGHT - 4);
    const gapWidth = WIDTH / Math.max(1, buckets.length - 1);
    const segments = (s: HostSeries) =>
        s.points
            .reduce<[number, number][][]>(
                (lines, p, i) => {
                    if (p.v === null) {
                        lines.push([]);
                    } else {
                        lines[lines.length - 1].push([x(i), y(p.v)]);
                    }

                    return lines;
                },
                [[]],
            )
            .filter((line) => line.length > 1);
    const isEdge = (s: HostSeries, i: number) =>
        s.points[i].v !== null &&
        ((s.points[i - 1]?.v ?? null) === null ||
            (s.points[i + 1]?.v ?? null) === null);
    const summary =
        values.length > 0
            ? t('overview.hosts.summary', {
                  title,
                  peak: format(Math.max(...values)),
              })
            : t('overview.charts.no_data');
    const step = 100 / Math.max(1, buckets.length - 1);
    const ticks =
        buckets.length > 1
            ? [0, 0.25, 0.5, 0.75, 1].map(
                  (f) => buckets[Math.round(f * (buckets.length - 1))],
              )
            : buckets;

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <h3 className="text-xs font-medium text-ink-2">{title}</h3>
            <div className="flex gap-2">
                <div
                    className="flex w-14 shrink-0 flex-col justify-between text-right font-mono text-[11px] text-ink-3"
                    aria-hidden="true"
                >
                    <span>{format(max)}</span>
                    <span>{format(max / 2)}</span>
                    <span>0</span>
                </div>
                <div className="relative min-w-0 flex-1">
                    <svg
                        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                        preserveAspectRatio="none"
                        className="block w-full"
                        style={{ height: HEIGHT }}
                        role="img"
                        aria-label={summary}
                    >
                        {buckets.map(
                            (bucket, i) =>
                                series.every((s) => s.points[i].v === null) && (
                                    <rect
                                        key={`gap-${bucket.t}`}
                                        x={x(i) - gapWidth / 2}
                                        width={gapWidth}
                                        y={0}
                                        height={HEIGHT}
                                        fill="var(--lj-line)"
                                        opacity={0.5}
                                    />
                                ),
                        )}
                        {[0, 0.5, 1].map((f) => (
                            <line
                                key={f}
                                x1={0}
                                x2={WIDTH}
                                y1={f * (HEIGHT - 1) + 0.5}
                                y2={f * (HEIGHT - 1) + 0.5}
                                stroke="var(--lj-line)"
                                strokeWidth={1}
                                vectorEffect="non-scaling-stroke"
                            />
                        ))}
                        {series.map((s) =>
                            segments(s).map((line, i) => (
                                <g key={`${s.name}-${i}`}>
                                    <path
                                        d={`${curve(line, true)} L${line[line.length - 1][0]},${HEIGHT} L${line[0][0]},${HEIGHT} Z`}
                                        fill={colorOf(s.name)}
                                        opacity={0.15}
                                    />
                                    <path
                                        d={curve(line, true)}
                                        fill="none"
                                        stroke={colorOf(s.name)}
                                        strokeWidth={1.5}
                                        vectorEffect="non-scaling-stroke"
                                    />
                                </g>
                            )),
                        )}
                    </svg>
                    <div className="absolute inset-0" aria-hidden="true">
                        {buckets.map((bucket, i) => (
                            <div
                                key={bucket.t}
                                className="group absolute inset-y-0 -translate-x-1/2"
                                style={{
                                    left: `${i * step}%`,
                                    width: `${step}%`,
                                }}
                            >
                                <div className="absolute inset-y-0 left-1/2 hidden w-px bg-line-strong group-hover:block" />
                                {series.map(
                                    (s) =>
                                        isEdge(s, i) && (
                                            <span
                                                key={s.name}
                                                className="absolute left-1/2 size-1.5 -translate-1/2 rounded-full"
                                                style={{
                                                    top: `${(y(s.points[i].v ?? 0) / HEIGHT) * 100}%`,
                                                    background: colorOf(s.name),
                                                }}
                                            />
                                        ),
                                )}
                                <div className="pointer-events-none absolute top-0 left-1/2 z-10 hidden -translate-x-1/2 -translate-y-full rounded border border-line-strong bg-panel-2 px-2 py-1 font-mono text-[11px] whitespace-nowrap text-ink shadow-lg group-hover:block">
                                    <div className="text-ink-3">
                                        {bucketLabel(bucket.t, period)}
                                    </div>
                                    {series.map((s) => (
                                        <div key={s.name}>
                                            {s.name}{' '}
                                            {s.points[i].v === null
                                                ? '-'
                                                : format(s.points[i].v)}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
            {buckets.length > 1 && (
                <div
                    className="ml-16 flex justify-between font-mono text-[11px] text-ink-3"
                    aria-hidden="true"
                >
                    {ticks.map((bucket, i) => (
                        <span key={i} className="whitespace-nowrap">
                            {bucketLabel(bucket.t, period)}
                        </span>
                    ))}
                </div>
            )}
            <p className="text-xs text-ink-3">{summary}</p>
        </div>
    );
}

/**
 * The overview's figures per host over the period, with a focus on one host for them all.
 */
export function HostChartsCard({
    charts,
    period,
}: {
    charts: HostCharts;
    period: Period;
}) {
    const t = useT();
    const [focus, setFocus] = useState('');
    const hosts = [
        ...new Set(
            Object.values(charts)
                .flat()
                .map((s) => s.name),
        ),
    ].sort();

    if (hosts.length === 0) {
        return null;
    }

    // A host can drop out on a live reload; the charts then show every host again.
    const focused = hosts.includes(focus) ? focus : '';
    const shown = (series: HostSeries[]) =>
        focused === '' ? series : series.filter((s) => s.name === focused);
    const colorOf = (host: string) =>
        COLORS[hosts.indexOf(host) % COLORS.length];

    const percent = (v: number) => formatPercent(v, 0);
    const panels = [
        {
            title: t('overview.hosts.cpu'),
            series: charts.cpu_load,
            format: percent,
        },
        {
            title: t('overview.hosts.memory'),
            series: charts.memory_used,
            format: percent,
        },
        {
            title: t('overview.hosts.disk'),
            series: charts.disk_used,
            format: percent,
        },
        {
            title: t('overview.hosts.p95'),
            series: charts.p95,
            // Milliseconds from the metrics, microseconds in the formatter.
            format: (v: number) => formatDuration(v * 1000),
        },
        {
            title: t('overview.hosts.exceptions'),
            series: charts.occurrences,
            format: formatNumber,
        },
        {
            title: t('overview.hosts.failed_jobs'),
            series: charts.job_failures,
            format: formatNumber,
        },
    ];

    return (
        <Card aria-labelledby="overview-hosts">
            <CardHeader
                id="overview-hosts"
                title={t('overview.hosts.title')}
                aside={
                    <span className="flex items-center gap-2">
                        <Label
                            htmlFor="overview-hosts-focus"
                            className="text-xs text-ink-3"
                        >
                            {t('overview.hosts.focus')}
                        </Label>
                        <Select
                            id="overview-hosts-focus"
                            value={focused}
                            onChange={(e) => setFocus(e.target.value)}
                        >
                            <option value="">{t('overview.hosts.all')}</option>
                            {hosts.map((host) => (
                                <option key={host} value={host}>
                                    {host}
                                </option>
                            ))}
                        </Select>
                    </span>
                }
            />
            <div className="flex flex-col gap-3 p-4">
                <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    {panels
                        .filter((panel) => panel.series.length > 0)
                        .map((panel) => (
                            <HostChart
                                key={panel.title}
                                title={panel.title}
                                series={shown(panel.series)}
                                period={period}
                                colorOf={colorOf}
                                format={panel.format}
                                whole={panel.format === formatNumber}
                            />
                        ))}
                </div>
                <ul className="flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-ink-2">
                    {hosts.map((host) => (
                        <li key={host}>
                            <button
                                type="button"
                                aria-pressed={focused === host}
                                onClick={() =>
                                    setFocus(focused === host ? '' : host)
                                }
                                className={cn(
                                    'flex items-center gap-1.5 rounded hover:text-ink focus-visible:outline-2 focus-visible:outline-amber',
                                    focused === host &&
                                        'text-ink underline underline-offset-4',
                                    focused !== '' &&
                                        focused !== host &&
                                        'opacity-50',
                                )}
                            >
                                <span
                                    aria-hidden="true"
                                    className="h-0.5 w-3 rounded"
                                    style={{ background: colorOf(host) }}
                                />
                                {host}
                            </button>
                        </li>
                    ))}
                    <li className="flex items-center gap-1.5 font-sans text-ink-3">
                        <span
                            aria-hidden="true"
                            className="h-2.5 w-3 rounded-sm bg-line"
                        />
                        {t('overview.hosts.no_data')}
                    </li>
                </ul>
            </div>
        </Card>
    );
}
