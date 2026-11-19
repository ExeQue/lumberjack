import type { Rule } from '@/components/lj/alerts';
import { Relative } from '@/components/lj/relative';
import {
    Badge,
    Card,
    CardHeader,
    EmptyState,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type AlertTopic = Rule & {
    topic: string;
    deletable: boolean;
    last_day: number;
    last_at: string | null;
};

export type SentAlert = {
    id: number;
    title: string;
    message: string | null;
    topic: string;
    level: 'info' | 'success' | 'warning' | 'critical';
    project: string;
    environment: string | null;
    host: string | null;
    occurred_at: string;
    suppressed: 'dedupe' | 'rate_limit' | null;
    deliveries: { channel: string; type: string; delivered: boolean }[] | null;
};

const levelTone: Record<SentAlert['level'], Tone> = {
    info: 'info',
    success: 'ok',
    warning: 'warn',
    critical: 'err',
};

function DeliveryState({ alert }: { alert: SentAlert }) {
    const t = useT();

    if (alert.suppressed !== null) {
        return <Badge>{t(`alerts.sent.suppressed.${alert.suppressed}`)}</Badge>;
    }

    if (alert.deliveries === null) {
        return <Badge>{t('alerts.sent.pending')}</Badge>;
    }

    const delivered = alert.deliveries.filter((d) => d.delivered).length;

    return alert.deliveries.length === 0 ? (
        <Badge tone="warn">{t('alerts.sent.no_channels')}</Badge>
    ) : (
        <span
            title={alert.deliveries
                .map(
                    (d) =>
                        `${d.channel}: ${t(d.delivered ? 'alerts.channels.delivered' : 'alerts.channels.failed')}`,
                )
                .join('\n')}
        >
            <Badge tone={delivered === alert.deliveries.length ? 'ok' : 'err'}>
                {t('alerts.sent.delivered', {
                    delivered,
                    total: alert.deliveries.length,
                })}
            </Badge>
        </span>
    );
}

export function SentAlertsCard({ alerts }: { alerts: SentAlert[] }) {
    const t = useT();

    return (
        <Card aria-labelledby="h-sent-alerts">
            <CardHeader
                id="h-sent-alerts"
                title={t('alerts.sent.title')}
                description={t('alerts.sent.description')}
            />
            {alerts.length === 0 ? (
                <EmptyState title={t('alerts.sent.empty')} />
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableClass}>
                        <thead>
                            <tr>
                                {(
                                    [
                                        'alert',
                                        'topic',
                                        'project',
                                        'when',
                                        'delivery',
                                    ] as const
                                ).map((column) => (
                                    <th key={column} className={thClass}>
                                        {t(`alerts.sent.columns.${column}`)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {alerts.map((alert) => (
                                <tr key={alert.id}>
                                    <td className={tdClass}>
                                        <span className="flex flex-col gap-0.5">
                                            <span className="flex flex-wrap items-center gap-2">
                                                {alert.title}
                                                <Badge
                                                    tone={
                                                        levelTone[alert.level]
                                                    }
                                                >
                                                    {t(
                                                        `alerts.sent.levels.${alert.level}`,
                                                    )}
                                                </Badge>
                                            </span>
                                            {alert.message && (
                                                <span className="text-xs text-ink-3">
                                                    {alert.message}
                                                </span>
                                            )}
                                        </span>
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            'font-mono text-xs',
                                        )}
                                    >
                                        {alert.topic}
                                    </td>
                                    <td className={cn(tdClass, 'text-xs')}>
                                        {[alert.project, alert.environment]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </td>
                                    <td
                                        className={cn(
                                            tdClass,
                                            'text-xs whitespace-nowrap',
                                        )}
                                    >
                                        <Relative iso={alert.occurred_at} />
                                    </td>
                                    <td className={tdClass}>
                                        <DeliveryState alert={alert} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}
