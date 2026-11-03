import { Relative, useNow } from '@/components/lj/relative';
import { Head, router, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { CopyButton } from '@/components/lj/copy-text';
import { HealthCheckDialog } from '@/components/lj/health-check-dialog';
import { DashboardFilterControls, TopBar } from '@/components/lj/top-bar';
import {
    Badge,
    bucketLabel,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Mono,
    PageTitle,
    TimeBars,
    numClass,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { PageBody } from '@/layouts/lumberjack-layout';
import {
    formatDateTime,
    formatDuration,
    formatNumber,
    formatPercent,
} from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { useProjectLive } from '@/lib/use-project-live';
import {
    archive as healthArchive,
    destroy as healthDestroy,
    index as healthIndex,
} from '@/routes/health';
import { show as projectShow } from '@/routes/projects';
import type {
    DashboardFilters,
    HealthCheckDetail,
    HealthCheckRow,
    HealthCheckType,
    HealthResultStatus,
    HealthState,
    ProjectHeader,
    TeamShared,
} from '@/types/lumberjack';

type Filters = DashboardFilters & { check: string | null };

type Props = {
    project: ProjectHeader;
    filters: Filters;
    checks: HealthCheckRow[];
    detail: HealthCheckDetail | null;
    types: HealthCheckType[];
    intervals: number[];
};

const stateTone: Record<HealthState, Tone> = {
    ok: 'ok',
    recovering: 'info',
    degraded: 'warn',
    down: 'err',
    stale: 'neutral',
    pending: 'neutral',
};

const resultTone: Record<HealthResultStatus, Tone> = {
    ok: 'ok',
    warning: 'warn',
    failed: 'err',
};

const stripColor: Record<string, string> = {
    ok: 'bg-ok',
    degraded: 'bg-warn',
    down: 'bg-err',
};

/** The latency the check measured itself, else how long the check took. */
function latency(metrics: Record<string, number>, durationUs: number | null) {
    return metrics.latency_ms !== undefined
        ? `${formatNumber(metrics.latency_ms, metrics.latency_ms < 10 ? 1 : 0)} ms`
        : formatDuration(durationUs);
}

/**
 * Uptime that keeps counting between results: while a check is down, its share of the
 * period drops every second, as the relative times move on.
 */
function LiveUptime({
    check,
}: {
    check: {
        uptime: number | null;
        uptime_live: HealthCheckRow['uptime_live'];
    };
}) {
    const now = useNow();
    const basis = check.uptime_live;

    if (check.uptime === null || basis === null) {
        return '-';
    }

    const from = new Date(basis.from).getTime();
    const total = (now - from) / 1000;
    const open = basis.down_since
        ? Math.max(0, (now - new Date(basis.down_since).getTime()) / 1000)
        : 0;

    return total > 0
        ? formatPercent(Math.max(0, 1 - (basis.down_s + open) / total), 2)
        : formatPercent(check.uptime, 2);
}

function When({ iso }: { iso: string | null }) {
    const t = useT();

    return iso ? (
        <time dateTime={iso} title={formatDateTime(iso)}>
            <Relative iso={iso} />
        </time>
    ) : (
        <span className="text-ink-3">{t('health.never')}</span>
    );
}

function StateBadge({ state }: { state: HealthState }) {
    const t = useT();

    return <Badge tone={stateTone[state]}>{t(`health.states.${state}`)}</Badge>;
}

/**
 * One segment per bucket of the period, colored by the worst the check was in it.
 */
function StatusStrip({
    strip,
    period,
}: {
    strip: HealthCheckRow['strip'];
    period: string;
}) {
    const t = useT();

    return (
        <span
            role="img"
            aria-label={t('health.strip_label')}
            className="flex h-4 min-w-32 items-stretch gap-px"
        >
            {strip.map((bucket, i) => (
                <span
                    key={i}
                    className={cn(
                        'group relative flex-1 rounded-[1px] hover:brightness-125',
                        bucket.s ? stripColor[bucket.s] : 'bg-line',
                    )}
                >
                    <span
                        className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded border border-line-strong bg-panel-2 px-2 py-1 font-mono text-[11px] whitespace-nowrap text-ink shadow-lg group-hover:block"
                        aria-hidden="true"
                    >
                        {bucketLabel(bucket.t, period)} ·{' '}
                        {bucket.s
                            ? t(`health.states.${bucket.s}`)
                            : t('health.no_data')}
                    </span>
                </span>
            ))}
        </span>
    );
}

function ChecksTable({
    checks,
    filters,
    onSelect,
}: {
    checks: HealthCheckRow[];
    filters: Filters;
    onSelect: (uuid: string | null) => void;
}) {
    const t = useT();

    return (
        <div className="overflow-x-auto">
            <table className={tableClass}>
                <thead>
                    <tr>
                        <th className={thClass}>{t('health.columns.check')}</th>
                        <th className={thClass}>
                            {t('health.columns.status')}
                        </th>
                        <th className={thClass}>
                            {t('health.columns.last_result')}
                        </th>
                        <th className={cn(thClass, 'text-right')}>
                            {t('health.columns.latency')}
                        </th>
                        <th className={cn(thClass, 'text-right')}>
                            {t('health.columns.uptime')}
                        </th>
                        <th className={cn(thClass, 'w-1/4')}>
                            {t('health.columns.history')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {checks.map((check) => (
                        <tr
                            key={check.uuid}
                            aria-selected={check.uuid === filters.check}
                            className={cn(
                                'lj-row hover:bg-panel-2/60',
                                check.uuid === filters.check && 'bg-panel-2',
                            )}
                        >
                            <td className={tdClass}>
                                <button
                                    type="button"
                                    onClick={() =>
                                        onSelect(
                                            check.uuid === filters.check
                                                ? null
                                                : check.uuid,
                                        )
                                    }
                                    className="lj-row-link text-left font-medium hover:text-amber"
                                >
                                    {check.name}
                                </button>
                                <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-3">
                                    {check.source === 'reported' &&
                                        check.custom_name && (
                                            <Mono>{check.slug}</Mono>
                                        )}
                                    {check.type && (
                                        <span>
                                            {t(`health.types.${check.type}`)}
                                        </span>
                                    )}
                                    {check.environment && (
                                        <Mono>{check.environment}</Mono>
                                    )}
                                    {check.muted && (
                                        <Badge>{t('health.muted')}</Badge>
                                    )}
                                </span>
                                {check.hosts.length > 1 && (
                                    <span
                                        className="mt-1 flex flex-wrap gap-1"
                                        aria-label={t('health.hosts_label')}
                                    >
                                        {check.hosts.map((host) => (
                                            <Badge
                                                key={host.host ?? ''}
                                                tone={stateTone[host.state]}
                                                title={
                                                    host.last_message ??
                                                    undefined
                                                }
                                            >
                                                <Mono>{host.host ?? '-'}</Mono>
                                            </Badge>
                                        ))}
                                    </span>
                                )}
                            </td>
                            <td className={tdClass}>
                                <StateBadge state={check.state} />
                            </td>
                            <td
                                className={cn(
                                    tdClass,
                                    'max-w-72 text-xs text-ink-2',
                                )}
                            >
                                <When iso={check.last_result_at} />
                                {check.last_message && (
                                    <span
                                        className="block truncate text-ink-3"
                                        title={check.last_message}
                                    >
                                        {check.last_message}
                                    </span>
                                )}
                            </td>
                            <td className={cn(tdClass, numClass)}>
                                {check.last_result_at
                                    ? latency(check.metrics, check.duration_us)
                                    : '-'}
                            </td>
                            <td className={cn(tdClass, numClass)}>
                                <LiveUptime check={check} />
                            </td>
                            <td className={tdClass}>
                                <StatusStrip
                                    strip={check.strip}
                                    period={filters.period}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function HealthIndex({
    project,
    filters,
    checks,
    detail,
    types,
    intervals,
}: Props) {
    const t = useT();
    const { team } = usePage<{ team: TeamShared }>().props;
    const [dialog, setDialog] = useState<HealthCheckRow | 'new' | null>(null);
    const active = checks.filter((check) => !check.archived);
    const reported = active.filter((check) => check.source === 'reported');
    const monitored = active.filter((check) => check.source === 'monitored');
    const archived = checks.filter((check) => check.archived);
    const selected = checks.find((check) => check.uuid === filters.check);
    const down = active.filter((check) => check.state === 'down').length;
    const degraded = active.filter(
        (check) => check.state === 'degraded',
    ).length;

    useProjectLive(project.uuid, ['health.changed'], ['checks', 'detail']);

    const select = (check: string | null) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, check }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        ) as Record<string, string>;

        router.get(
            healthIndex(project.uuid, { query }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`${t('health.title')} · ${project.name}`} />
            <TopBar
                crumbs={[
                    { label: team.name },
                    {
                        label: project.name,
                        href: projectShow(project.uuid).url,
                        mono: true,
                    },
                    { label: t('health.title') },
                ]}
            >
                <DashboardFilterControls
                    filters={filters}
                    environments={project.environments}
                />
            </TopBar>
            <PageBody wide>
                <PageTitle
                    title={t('health.title')}
                    description={t('health.description', {}, active.length)}
                    actions={
                        project.can_administer && (
                            <Button
                                variant="primary"
                                onClick={() => setDialog('new')}
                            >
                                {t('health.add')}
                            </Button>
                        )
                    }
                />

                {down + degraded > 0 && (
                    <div
                        role="status"
                        className={cn(
                            'flex items-center gap-2.5 rounded-lg border px-4 py-3',
                            down > 0
                                ? 'border-err-line bg-err-bg'
                                : 'border-warn-line bg-warn-bg',
                        )}
                    >
                        <TriangleAlert
                            className={cn(
                                'size-4 shrink-0',
                                down > 0 ? 'text-err' : 'text-warn',
                            )}
                            aria-hidden="true"
                        />
                        <strong className="font-semibold">
                            {t('health.attention', { down, degraded })}
                        </strong>
                    </div>
                )}

                {(['reported', 'monitored'] as const).map((source) => {
                    const list = source === 'reported' ? reported : monitored;

                    return (
                        <Card
                            key={source}
                            aria-labelledby={`health-${source}-title`}
                        >
                            <CardHeader
                                id={`health-${source}-title`}
                                title={t(`health.sources.${source}.title`)}
                                description={t(`health.sources.${source}.hint`)}
                            />
                            {list.length === 0 ? (
                                <EmptyState
                                    title={t(
                                        `health.sources.${source}.empty_title`,
                                    )}
                                >
                                    {t(`health.sources.${source}.empty_body`)}
                                </EmptyState>
                            ) : (
                                <ChecksTable
                                    checks={list}
                                    filters={filters}
                                    onSelect={select}
                                />
                            )}
                        </Card>
                    );
                })}

                {archived.length > 0 && (
                    <Card aria-labelledby="health-archived-title">
                        <CardHeader
                            id="health-archived-title"
                            title={t('health.sources.archived.title')}
                            description={t('health.sources.archived.hint')}
                        />
                        <ChecksTable
                            checks={archived}
                            filters={filters}
                            onSelect={select}
                        />
                    </Card>
                )}

                {selected && detail && detail.uuid === selected.uuid && (
                    <CheckDetail
                        project={project}
                        check={selected}
                        detail={detail}
                        period={filters.period}
                        onEdit={() => setDialog(selected)}
                        onClose={() => select(null)}
                    />
                )}
            </PageBody>

            {dialog !== null && (
                <HealthCheckDialog
                    project={project.uuid}
                    check={dialog === 'new' ? undefined : dialog}
                    types={types}
                    intervals={intervals}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

function CheckDetail({
    project,
    check,
    detail,
    period,
    onEdit,
    onClose,
}: {
    project: ProjectHeader;
    check: HealthCheckRow;
    detail: HealthCheckDetail;
    period: string;
    onEdit: () => void;
    onClose: () => void;
}) {
    const t = useT();
    const curl = detail.ping_url ? `curl -fsS -m 10 ${detail.ping_url}` : null;

    return (
        <Card aria-labelledby="health-detail-title">
            <CardHeader
                id="health-detail-title"
                title={check.name}
                description={
                    <>
                        {check.description && (
                            <span className="block">{check.description}</span>
                        )}
                        {t(
                            'health.detail.thresholds',
                            {
                                recover: check.recover_after,
                                down: check.down_after,
                            },
                            check.degrade_after,
                        )}{' '}
                        {check.source === 'reported'
                            ? t(
                                  check.thresholds_overridden
                                      ? 'health.detail.thresholds_own'
                                      : 'health.detail.thresholds_app',
                              )
                            : t(
                                  'health.detail.every',
                                  {},
                                  check.interval_minutes ?? 1,
                              )}
                    </>
                }
                aside={
                    <span className="flex flex-wrap gap-2">
                        {project.can_administer && (
                            <>
                                <Button onClick={onEdit}>
                                    {t('health.detail.edit')}
                                </Button>
                                {check.archived ? (
                                    <Button
                                        onClick={() =>
                                            router.put(
                                                healthArchive([
                                                    project.uuid,
                                                    check.uuid,
                                                ]).url,
                                                { archived: false },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('health.detail.unarchive')}
                                    </Button>
                                ) : (
                                    <ConfirmButton
                                        variant="default"
                                        question={t(
                                            'health.detail.archive_question',
                                            { check: check.name },
                                        )}
                                        confirmLabel={t(
                                            'health.detail.archive',
                                        )}
                                        onConfirm={() =>
                                            router.put(
                                                healthArchive([
                                                    project.uuid,
                                                    check.uuid,
                                                ]).url,
                                                { archived: true },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('health.detail.archive')}
                                    </ConfirmButton>
                                )}
                                <ConfirmButton
                                    question={t(
                                        'health.detail.delete_question',
                                        { check: check.name },
                                    )}
                                    confirmLabel={t('health.detail.delete')}
                                    onConfirm={() =>
                                        router.delete(
                                            healthDestroy([
                                                project.uuid,
                                                check.uuid,
                                            ]).url,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {t('health.detail.delete')}
                                </ConfirmButton>
                            </>
                        )}
                        <Button variant="ghost" onClick={onClose}>
                            {t('health.detail.close')}
                        </Button>
                    </span>
                }
            />

            <div className="flex flex-col gap-6 p-4">
                {detail.ping_url && curl && (
                    <section className="flex flex-col gap-2">
                        <h3 className="text-xs font-medium text-ink-3 uppercase">
                            {t('health.detail.ping.title')}
                        </h3>
                        <div className="flex flex-wrap items-center gap-2">
                            <code className="min-w-0 flex-1 truncate rounded-md border border-line bg-rail px-2.5 py-1.5 font-mono text-xs">
                                {detail.ping_url}
                            </code>
                            <CopyButton text={detail.ping_url} />
                        </div>
                        <p className="text-xs text-ink-3">
                            {t('health.detail.ping.hint')}{' '}
                            {t('health.detail.ping.example')}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <code className="min-w-0 flex-1 overflow-x-auto rounded-md border border-line bg-rail px-2.5 py-1.5 font-mono text-xs whitespace-nowrap">
                                {curl}
                            </code>
                            <CopyButton text={curl} />
                        </div>
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <h3 className="text-xs font-medium text-ink-3 uppercase">
                        {t('health.detail.latency')}
                    </h3>
                    <TimeBars
                        series={detail.latency_series}
                        period={period}
                        label={t('health.detail.latency')}
                        height={90}
                        formatValue={(v) => formatDuration(v)}
                    />
                </section>

                {check.hosts.length > 1 && (
                    <section className="flex flex-col gap-2">
                        <h3 className="text-xs font-medium text-ink-3 uppercase">
                            {t('health.detail.hosts')}
                        </h3>
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('health.columns.host')}
                                        </th>
                                        <th className={thClass}>
                                            {t('health.columns.status')}
                                        </th>
                                        <th className={thClass}>
                                            {t('health.columns.last_result')}
                                        </th>
                                        <th className={thClass}>
                                            {t('health.columns.message')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('health.columns.latency')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {check.hosts.map((host) => (
                                        <tr key={host.host ?? ''}>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'font-mono text-xs',
                                                )}
                                            >
                                                {host.host ?? '-'}
                                            </td>
                                            <td className={tdClass}>
                                                <StateBadge
                                                    state={host.state}
                                                />
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs text-ink-2',
                                                )}
                                            >
                                                <When
                                                    iso={host.last_result_at}
                                                />
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs text-ink-2',
                                                )}
                                            >
                                                {host.last_message ?? '-'}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {latency(
                                                    host.metrics,
                                                    host.duration_us,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <h3 className="text-xs font-medium text-ink-3 uppercase">
                        {t('health.detail.outages')}
                    </h3>
                    {detail.outages.length === 0 ? (
                        <p className="text-[13px] text-ink-3">
                            {t('health.detail.no_outages')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('health.columns.started')}
                                        </th>
                                        <th className={thClass}>
                                            {t('health.columns.ended')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('health.columns.duration')}
                                        </th>
                                        <th className={thClass}>
                                            {t('health.columns.worst')}
                                        </th>
                                        <th className={thClass}>
                                            {t('health.columns.message')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {detail.outages.map((outage) => (
                                        <tr key={outage.id}>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                {formatDateTime(
                                                    outage.started_at,
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                {outage.ended_at ? (
                                                    formatDateTime(
                                                        outage.ended_at,
                                                    )
                                                ) : (
                                                    <Badge tone="err">
                                                        {t('health.ongoing')}
                                                    </Badge>
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {formatDuration(
                                                    outage.duration_s *
                                                        1_000_000,
                                                )}
                                            </td>
                                            <td className={tdClass}>
                                                <StateBadge
                                                    state={outage.status}
                                                />
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs text-ink-2',
                                                )}
                                            >
                                                {outage.first_message ?? '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <h3 className="text-xs font-medium text-ink-3 uppercase">
                        {t('health.detail.results')}
                    </h3>
                    {detail.results.length === 0 ? (
                        <p className="text-[13px] text-ink-3">
                            {t('health.detail.no_results')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClass}>
                                <thead>
                                    <tr>
                                        <th className={thClass}>
                                            {t('health.columns.checked_at')}
                                        </th>
                                        <th className={thClass}>
                                            {t('health.columns.status')}
                                        </th>
                                        {check.source === 'reported' && (
                                            <th className={thClass}>
                                                {t('health.columns.host')}
                                            </th>
                                        )}
                                        <th className={thClass}>
                                            {t('health.columns.message')}
                                        </th>
                                        <th className={thClass}>
                                            {t('health.columns.metrics')}
                                        </th>
                                        <th
                                            className={cn(
                                                thClass,
                                                'text-right',
                                            )}
                                        >
                                            {t('health.columns.duration')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {detail.results.map((result) => (
                                        <tr key={result.id}>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs whitespace-nowrap text-ink-2',
                                                )}
                                            >
                                                <When iso={result.checked_at} />
                                            </td>
                                            <td className={tdClass}>
                                                <Badge
                                                    tone={
                                                        resultTone[
                                                            result.status
                                                        ]
                                                    }
                                                >
                                                    {t(
                                                        `health.result_statuses.${result.status}`,
                                                    )}
                                                </Badge>
                                            </td>
                                            {check.source === 'reported' && (
                                                <td
                                                    className={cn(
                                                        tdClass,
                                                        'font-mono text-xs',
                                                    )}
                                                >
                                                    {result.host ?? '-'}
                                                </td>
                                            )}
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'text-xs text-ink-2',
                                                )}
                                            >
                                                {result.message ?? '-'}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    'font-mono text-xs text-ink-3',
                                                )}
                                            >
                                                {Object.entries(result.metrics)
                                                    .map(
                                                        ([name, value]) =>
                                                            `${name} ${formatNumber(value, Number.isInteger(value) ? 0 : 1)}`,
                                                    )
                                                    .join(', ') || '-'}
                                            </td>
                                            <td
                                                className={cn(
                                                    tdClass,
                                                    numClass,
                                                )}
                                            >
                                                {formatDuration(
                                                    result.duration_us,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </Card>
    );
}
