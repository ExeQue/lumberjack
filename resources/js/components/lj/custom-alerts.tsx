import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { RuleRow } from '@/components/lj/alerts';
import type { Channel, Rule } from '@/components/lj/alerts';
import { ConfirmButton } from '@/components/lj/confirm-button';
import { Relative } from '@/components/lj/relative';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Input,
    tableClass,
    tdClass,
    thClass,
} from '@/components/lj/ui';
import type { Tone } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    destroy as topicDestroy,
    store as topicStore,
} from '@/routes/alerts/topics';

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

/**
 * Where apps' own alerts go. Folded by default: most visits are not about this, and a
 * topic that drops alerts still says so in the header.
 */
export function TopicsCard({
    topics,
    channels,
    canAdminister,
}: {
    topics: AlertTopic[];
    channels: Channel[];
    canAdminister: boolean;
}) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '' });
    const delivering = topics.filter(
        (topic) => topic.enabled && topic.channels.length > 0,
    ).length;
    const lost = topics.filter(
        (topic) =>
            topic.enabled && topic.channels.length === 0 && topic.last_day > 0,
    ).length;

    return (
        <Card aria-labelledby="h-topics">
            <h2 id="h-topics" className="text-[13px]">
                <button
                    type="button"
                    aria-expanded={open}
                    aria-controls="topics-body"
                    onClick={() => setOpen(!open)}
                    className="flex w-full cursor-pointer flex-wrap items-center gap-2.5 rounded-lg p-4 text-left hover:bg-panel-2/60 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-amber"
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'text-ink-3 transition-transform',
                            open && 'rotate-90',
                        )}
                    >
                        ▸
                    </span>
                    <span className="font-semibold">
                        {t('alerts.topics.title')}
                    </span>
                    <span className="text-xs font-normal text-ink-3">
                        {t(
                            'alerts.topics.summary',
                            { delivering },
                            topics.length,
                        )}
                    </span>
                    {lost > 0 && (
                        <Badge tone="warn">
                            {t('alerts.topics.not_delivered', {}, lost)}
                        </Badge>
                    )}
                </button>
            </h2>
            <div id="topics-body" hidden={!open}>
                <div className="flex flex-wrap items-start justify-between gap-3 border-t border-line p-4">
                    <p className="max-w-[70ch] text-xs text-ink-3">
                        {t('alerts.topics.description')}
                    </p>
                    {canAdminister && (
                        <form
                            className="flex flex-wrap items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(topicStore().url, {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset(),
                                });
                            }}
                        >
                            <Input
                                aria-label={t('alerts.topics.name')}
                                placeholder={t('alerts.topics.placeholder')}
                                maxLength={64}
                                className="w-44 font-mono"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            <Button type="submit" disabled={form.processing}>
                                {t('alerts.topics.create')}
                            </Button>
                            {form.errors.name && (
                                <p
                                    role="alert"
                                    className="basis-full text-xs text-err"
                                >
                                    {form.errors.name}
                                </p>
                            )}
                        </form>
                    )}
                </div>
                <div className="flex flex-col gap-3 p-4 pt-0">
                    {topics.map((topic) => (
                        <RuleRow
                            key={`${topic.id}:${topic.channels.join(',')}:${topic.enabled}`}
                            rule={{
                                ...topic,
                                description: !topic.deletable
                                    ? t('alerts.topics.default_description')
                                    : t(
                                          'alerts.topics.count',
                                          {},
                                          topic.last_day,
                                      ),
                            }}
                            channels={channels}
                            disabled={!canAdminister}
                            teamLevel
                            actions={
                                canAdminister &&
                                topic.deletable && (
                                    <ConfirmButton
                                        variant="default"
                                        question={t(
                                            'alerts.topics.delete_question',
                                            { topic: topic.topic },
                                        )}
                                        confirmLabel={t('common.delete')}
                                        onConfirm={() =>
                                            router.delete(
                                                topicDestroy(topic.id).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('common.delete')}
                                        <span className="sr-only">
                                            {' '}
                                            {topic.topic}
                                        </span>
                                    </ConfirmButton>
                                )
                            }
                        />
                    ))}
                </div>
            </div>
        </Card>
    );
}

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
