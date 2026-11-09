import { Relative } from '@/components/lj/relative';
import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ChannelPicker } from '@/components/lj/channel-picker';
import { ConfirmButton } from '@/components/lj/confirm-button';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    Switch,
} from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    destroy as channelDestroy,
    store as channelStore,
    test as channelTest,
    update as channelUpdate,
} from '@/routes/alerts/channels';
import {
    addOns as ruleAddOns,
    customize as ruleCustomize,
    reset as ruleReset,
    update as ruleUpdate,
} from '@/routes/alerts/rules';
import type { AlertRule, ProjectAlertRule } from '@/types/lumberjack';

export type Channel = {
    id: number;
    type: string;
    name: string;
    target: string;
    last_delivery_status: string | null;
    last_delivery_at: string | null;
    settings?: Record<string, string>;
};
export type Rule = AlertRule;

const configField: Record<
    string,
    { key: string; type: string; placeholder: string }
> = {
    discord: {
        key: 'webhook_url',
        type: 'url',
        placeholder: 'https://discord.com/api/webhooks/...',
    },
    mail: { key: 'address', type: 'email', placeholder: 'ops@example.com' },
    webhook: {
        key: 'url',
        type: 'url',
        placeholder: 'https://app.example.com/lumberjack/webhook',
    },
    slack: {
        key: 'webhook_url',
        type: 'url',
        placeholder: 'https://hooks.slack.com/services/...',
    },
    teams: {
        key: 'webhook_url',
        type: 'url',
        placeholder: 'https://....logic.azure.com/workflows/...',
    },
    telegram: {
        key: 'bot_token',
        type: 'password',
        placeholder: '123456789:AA...',
    },
    ntfy: { key: 'url', type: 'url', placeholder: 'https://ntfy.sh/my-topic' },
    pushover: { key: 'user_key', type: 'password', placeholder: 'u...' },
};

// Optional, non-secret fields after the main one.
const extraFields: Record<
    string,
    { key: string; type: string; placeholder: string; maxLength: number }[]
> = {
    discord: [
        {
            key: 'sender_name',
            type: 'text',
            placeholder: 'Lumberjack',
            maxLength: 80,
        },
    ],
    telegram: [
        {
            key: 'chat_id',
            type: 'text',
            placeholder: '-1001234567890',
            maxLength: 64,
        },
    ],
    // Secrets too: left empty when editing, they keep what is stored.
    ntfy: [
        {
            key: 'access_token',
            type: 'password',
            placeholder: 'tk_...',
            maxLength: 200,
        },
    ],
    pushover: [
        {
            key: 'app_token',
            type: 'password',
            placeholder: 'a...',
            maxLength: 30,
        },
    ],
};

function ExtraFields({
    type,
    idPrefix,
    errors,
    values,
    onChange,
}: {
    type: string;
    idPrefix: string;
    errors: Record<string, string>;
    values?: Record<string, string>;
    onChange?: (key: string, value: string) => void;
}) {
    const t = useT();

    return (
        <>
            {(extraFields[type] ?? []).map((extra) => (
                <Field
                    key={extra.key}
                    label={t(`alerts.channels.config.${extra.key}`)}
                    htmlFor={`${idPrefix}-${extra.key}`}
                    hint={t(`alerts.channels.config.${extra.key}_hint`)}
                    error={errors[`config.${extra.key}`]}
                    className="min-w-0 flex-[1_1_180px]"
                >
                    <Input
                        id={`${idPrefix}-${extra.key}`}
                        name={`config[${extra.key}]`}
                        type={extra.type}
                        maxLength={extra.maxLength}
                        placeholder={extra.placeholder}
                        defaultValue={values?.[extra.key] ?? ''}
                        onChange={(e) => onChange?.(extra.key, e.target.value)}
                        autoComplete="off"
                    />
                </Field>
            ))}
        </>
    );
}

/**
 * Alert channels, added and edited in a dialog. With a project, they are that
 * project's own channels; without, the team's shared ones.
 */
export function ChannelsCard({
    channels,
    types,
    canAdminister,
    project,
    description,
}: {
    channels: Channel[];
    types: string[];
    canAdminister: boolean;
    project?: string;
    description?: string;
}) {
    const t = useT();
    // null: closed; 'new': adding one; a channel: editing it.
    const [dialog, setDialog] = useState<Channel | 'new' | null>(null);

    return (
        <Card aria-labelledby="h-channels">
            <CardHeader
                id="h-channels"
                title={t('alerts.channels.title')}
                description={description ?? t('alerts.channels.description')}
            />
            <div className="flex flex-col gap-4 p-4">
                {channels.length === 0 ? (
                    <EmptyState title={t('alerts.channels.empty')} />
                ) : (
                    <ul className="flex flex-col gap-2">
                        {channels.map((channel) => (
                            <li
                                key={channel.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line px-3 py-2.5"
                            >
                                <span className="flex min-w-0 flex-wrap items-center gap-2.5">
                                    <Badge>
                                        {t(`alerts.types.${channel.type}`)}
                                    </Badge>
                                    <span className="font-medium">
                                        {channel.name}
                                    </span>
                                    <span className="min-w-0 font-mono text-xs break-all text-ink-3">
                                        {channel.target}
                                    </span>
                                </span>
                                <span className="flex flex-wrap items-center gap-2">
                                    <DeliveryBadge channel={channel} />
                                    {canAdminister && (
                                        <>
                                            <Button
                                                onClick={() =>
                                                    setDialog(channel)
                                                }
                                            >
                                                {t('alerts.channels.edit')}
                                                <span className="sr-only">
                                                    {' '}
                                                    {channel.name}
                                                </span>
                                            </Button>
                                            <Button
                                                onClick={() =>
                                                    router.post(
                                                        channelTest(channel.id)
                                                            .url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('alerts.channels.test')}
                                                <span className="sr-only">
                                                    {' '}
                                                    {channel.name}
                                                </span>
                                            </Button>
                                            <ConfirmButton
                                                question={t(
                                                    'alerts.channels.delete_question',
                                                    { name: channel.name },
                                                )}
                                                confirmLabel={t(
                                                    'common.delete',
                                                )}
                                                onConfirm={() =>
                                                    router.delete(
                                                        channelDestroy(
                                                            channel.id,
                                                        ).url,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('common.delete')}
                                                <span className="sr-only">
                                                    {' '}
                                                    {channel.name}
                                                </span>
                                            </ConfirmButton>
                                        </>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                {canAdminister && (
                    <div>
                        <Button onClick={() => setDialog('new')}>
                            {t('alerts.channels.add_new')}
                        </Button>
                    </div>
                )}
            </div>
            {dialog !== null && (
                <ChannelDialog
                    // A fresh wizard each time it opens.
                    key={dialog === 'new' ? 'new' : dialog.id}
                    channel={dialog === 'new' ? undefined : dialog}
                    types={types}
                    project={project}
                    onClose={() => setDialog(null)}
                />
            )}
        </Card>
    );
}

/**
 * Adding a channel in two steps: where alerts go, then how to reach it, with setup
 * help and a preview of a message. Editing starts at the second step.
 */
function ChannelDialog({
    channel,
    types,
    project,
    onClose,
}: {
    channel?: Channel;
    types: string[];
    project?: string;
    onClose: () => void;
}) {
    const t = useT();
    const [type, setType] = useState<string | null>(channel?.type ?? null);
    const [sender, setSender] = useState(channel?.settings?.sender_name ?? '');
    const field =
        type === null
            ? null
            : (configField[type] ?? {
                  key: 'address',
                  type: 'text',
                  placeholder: '',
              });
    const form = channel ? channelUpdate.form(channel.id) : channelStore.form();

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        {channel
                            ? t('alerts.wizard.edit_title', {
                                  name: channel.name,
                              })
                            : t('alerts.wizard.add_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {type === null
                            ? t('alerts.wizard.pick_type')
                            : t(`alerts.wizard.setup.${type}`)}
                    </DialogDescription>
                </DialogHeader>

                {type === null || field === null ? (
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-2">
                        {types.map((value) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setType(value)}
                                className="flex flex-col gap-1 rounded-md border border-line bg-panel px-3 py-2.5 text-left hover:border-amber"
                            >
                                <span className="font-medium text-ink">
                                    {t(`alerts.types.${value}`)}
                                </span>
                                <span className="text-xs text-ink-3">
                                    {t(`alerts.wizard.about.${value}`)}
                                </span>
                            </button>
                        ))}
                    </div>
                ) : (
                    <Form
                        {...form}
                        options={{ preserveScroll: true }}
                        transform={(data) =>
                            project && !channel ? { ...data, project } : data
                        }
                        onSuccess={onClose}
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <input type="hidden" name="type" value={type} />
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="flex flex-col gap-3">
                                        <Field
                                            label={t('alerts.channels.name')}
                                            htmlFor="channel-name"
                                            error={errors.name}
                                        >
                                            <Input
                                                id="channel-name"
                                                name="name"
                                                required
                                                autoFocus
                                                maxLength={100}
                                                defaultValue={channel?.name}
                                                placeholder={t(
                                                    'alerts.channels.name_placeholder',
                                                )}
                                                autoComplete="off"
                                            />
                                        </Field>
                                        <Field
                                            label={t(
                                                `alerts.channels.config.${field.key}`,
                                            )}
                                            htmlFor="channel-config"
                                            hint={
                                                channel
                                                    ? t(
                                                          'alerts.channels.keep_hint',
                                                      )
                                                    : undefined
                                            }
                                            error={
                                                (
                                                    errors as Record<
                                                        string,
                                                        string
                                                    >
                                                )[`config.${field.key}`] ??
                                                errors.config
                                            }
                                        >
                                            <Input
                                                id="channel-config"
                                                name={`config[${field.key}]`}
                                                type={field.type}
                                                required={!channel}
                                                className="font-mono"
                                                placeholder={
                                                    channel?.target ??
                                                    field.placeholder
                                                }
                                                autoComplete="off"
                                            />
                                        </Field>
                                        <ExtraFields
                                            type={type}
                                            idPrefix="channel"
                                            errors={
                                                errors as Record<string, string>
                                            }
                                            values={channel?.settings}
                                            onChange={(key, value) =>
                                                key === 'sender_name' &&
                                                setSender(value)
                                            }
                                        />
                                    </div>
                                    <ChannelPreview
                                        type={type}
                                        sender={sender}
                                    />
                                </div>
                                <DialogFooter>
                                    {!channel && (
                                        <Button
                                            onClick={() => setType(null)}
                                            className="sm:mr-auto"
                                        >
                                            {t('alerts.wizard.back')}
                                        </Button>
                                    )}
                                    <Button onClick={onClose}>
                                        {t('common.cancel')}
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        disabled={processing}
                                    >
                                        {channel
                                            ? t('common.save')
                                            : t('alerts.channels.add')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Alert rules: which kinds of alert go to which channels. The team's are edited as
 * they are; a project's show, per type, the team's (read only, to customize) or the
 * project's own (editable, to drop again).
 */
export function RulesCard(
    props: {
        channels: Channel[];
        canAdminister: boolean;
    } & (
        | { scope: 'team'; rules: AlertRule[] }
        | { scope: 'project'; project: string; rules: ProjectAlertRule[] }
    ),
) {
    const t = useT();
    const { channels, canAdminister } = props;
    // Team rules on without a team channel; projects can still add their own, so one line, not a warning per rule.
    const silent =
        props.scope === 'team' && channels.length > 0
            ? props.rules.filter((r) => r.enabled && r.channels.length === 0)
                  .length
            : 0;

    return (
        <Card aria-labelledby="h-rules">
            <CardHeader
                id="h-rules"
                title={t('alerts.rules.title')}
                description={t(`alerts.rules.${props.scope}_description`)}
            />
            <div className="flex flex-col gap-3 p-4">
                {silent > 0 && (
                    <p className="text-xs text-ink-2">
                        {t(
                            'alerts.rules.without_team_channel',
                            { count: silent },
                            silent,
                        )}
                    </p>
                )}
                {props.rules.length === 0 ? (
                    <EmptyState title={t('alerts.rules.empty')} />
                ) : props.scope === 'team' ? (
                    props.rules.map((rule) => (
                        <RuleRow
                            key={ruleKey(rule)}
                            rule={rule}
                            channels={channels}
                            disabled={!canAdminister}
                            teamLevel
                        />
                    ))
                ) : (
                    props.rules.map(({ project: own, ...row }) =>
                        own ? (
                            <RuleRow
                                key={ruleKey(own)}
                                rule={own}
                                channels={channels}
                                disabled={!canAdminister}
                                badge={
                                    <Badge tone="amber">
                                        {t('alerts.rules.customized')}
                                    </Badge>
                                }
                                actions={
                                    canAdminister && (
                                        <ConfirmButton
                                            variant="default"
                                            question={t(
                                                'alerts.rules.use_team_question',
                                                { rule: row.label },
                                            )}
                                            confirmLabel={t(
                                                'alerts.rules.use_team',
                                            )}
                                            onConfirm={() =>
                                                router.delete(
                                                    ruleReset(own.id).url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('alerts.rules.use_team')}
                                            <span className="sr-only">
                                                {' '}
                                                {row.label}
                                            </span>
                                        </ConfirmButton>
                                    )
                                }
                            />
                        ) : (
                            <TeamRuleSummary
                                key={row.type}
                                row={row}
                                project={props.project}
                                channels={channels}
                                canAdminister={canAdminister}
                            />
                        ),
                    )
                )}
                <p className="text-xs text-ink-3">{t('alerts.rules.hint')}</p>
            </div>
        </Card>
    );
}

/** A type the project takes from the team: what the team's rule does, read only. */
function TeamRuleSummary({
    row,
    project,
    channels,
    canAdminister,
}: {
    row: Omit<ProjectAlertRule, 'project'>;
    project: string;
    channels: Channel[];
    canAdminister: boolean;
}) {
    const t = useT();
    const rule = row.team;
    const names = channels
        .filter((channel) => rule?.channels.includes(channel.id))
        .map((channel) => channel.name);
    // Any channel the team's rule does not already send to can be added for this project.
    const extra = channels.filter(
        (channel) => !rule?.channels.includes(channel.id),
    );
    const saveAddOns = (ids: number[]) =>
        rule &&
        router.put(
            ruleAddOns({ project, rule: rule.id }).url,
            { channels: ids },
            { preserveScroll: true },
        );

    return (
        <div className="flex flex-wrap items-start gap-4 rounded-lg border border-dashed border-line bg-rail p-3.5">
            <div className="flex min-w-0 flex-[1_1_240px] flex-col gap-1">
                <span className="flex flex-wrap items-center gap-2 font-semibold">
                    {row.label}
                    <span className="text-xs font-normal text-ink-3">
                        {rule?.enabled
                            ? t('alerts.rules.on')
                            : t('alerts.rules.off')}
                    </span>
                    <Badge>{t('alerts.rules.team_default')}</Badge>
                </span>
                <span className="text-ink-2">{row.description}</span>
            </div>
            <div className="flex flex-[1_1_180px] flex-col gap-1 text-xs text-ink-3">
                <span title={names.join(', ') || undefined}>
                    {names.length > 0
                        ? t('alerts.rules.sends_to', {
                              channels: t(
                                  'alerts.rules.channel_count',
                                  {},
                                  names.length,
                              ),
                          })
                        : t('alerts.rules.nowhere')}
                </span>
                {rule && rule.environments.length > 0 && (
                    <span>
                        {t('alerts.rules.only_in', {
                            environments: rule.environments.join(', '),
                        })}
                    </span>
                )}
                {rule && extra.length > 0 && (
                    <div className="mt-1.5 flex flex-col gap-1">
                        <span>
                            {rule.enabled
                                ? t('alerts.rules.add_ons')
                                : t('alerts.rules.add_ons_off')}
                        </span>
                        <ChannelPicker
                            title={row.label}
                            channels={extra}
                            selected={row.add_ons}
                            onChange={saveAddOns}
                            disabled={!canAdminister}
                        />
                    </div>
                )}
            </div>
            {canAdminister && (
                <Button
                    onClick={() =>
                        router.post(
                            ruleCustomize({ project, type: row.type }).url,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    {t('alerts.rules.customize')}
                    <span className="sr-only"> {row.label}</span>
                </Button>
            )}
        </div>
    );
}

function DeliveryBadge({ channel }: { channel: Channel }) {
    const t = useT();

    if (channel.last_delivery_status === null) {
        return <Badge>{t('alerts.channels.never_delivered')}</Badge>;
    }

    const ok = channel.last_delivery_status === 'sent';

    return (
        <Badge tone={ok ? 'ok' : 'err'}>
            {ok ? t('alerts.channels.delivered') : t('alerts.channels.failed')}
            {channel.last_delivery_at && (
                <span className="font-normal opacity-80">
                    <Relative iso={channel.last_delivery_at} />
                </span>
            )}
        </Badge>
    );
}

/** A rule row keeps what is typed in local state; it starts over when the rule changes elsewhere. */
function ruleKey(rule: Rule): string {
    return `${rule.id}:${JSON.stringify([rule.enabled, rule.channels, rule.environments, rule.settings])}`;
}

function RuleRow({
    rule,
    channels,
    disabled,
    badge,
    actions,
    teamLevel = false,
}: {
    rule: Rule;
    channels: Channel[];
    disabled: boolean;
    badge?: ReactNode;
    actions?: ReactNode;
    /** A team rule with no channel ticked can still send through a project's add-ons. */
    teamLevel?: boolean;
}) {
    const t = useT();
    const [enabled, setEnabled] = useState(rule.enabled);
    const [selected, setSelected] = useState<number[]>(rule.channels);
    const [environments, setEnvironments] = useState(
        rule.environments.join(', '),
    );
    // Number inputs as typed; empty means the threshold is off.
    const [thresholds, setThresholds] = useState({
        size: String(rule.settings?.size ?? ''),
        age_minutes: String(rule.settings?.age_minutes ?? ''),
        sustain_minutes: String(rule.settings?.sustain_minutes ?? ''),
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const save = () => {
        const envs = environments
            .split(',')
            .map((e) => e.trim())
            .filter((e) => e !== '');

        router.put(
            ruleUpdate(rule.id).url,
            {
                enabled,
                channels: selected,
                environments: envs.length > 0 ? envs : null,
                ...(rule.settings && {
                    settings: Object.fromEntries(
                        Object.entries(thresholds).map(([key, value]) => [
                            key,
                            value.trim() === '' ? null : Number(value),
                        ]),
                    ),
                }),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (e) => setErrors(e),
                onSuccess: () => setErrors({}),
            },
        );
    };

    const firstError = Object.values(errors)[0];
    // Save shows once something differs from the stored rule.
    const dirty =
        enabled !== rule.enabled ||
        selected.length !== rule.channels.length ||
        selected.some((id) => !rule.channels.includes(id)) ||
        environments !== rule.environments.join(', ') ||
        (rule.settings !== null &&
            (['size', 'age_minutes', 'sustain_minutes'] as const).some(
                (key) => thresholds[key] !== String(rule.settings?.[key] ?? ''),
            ));

    return (
        <form
            className="flex flex-wrap items-start gap-4 rounded-lg border border-line bg-rail p-3.5"
            onSubmit={(e) => {
                e.preventDefault();
                save();
            }}
        >
            <Switch
                checked={enabled}
                onChange={setEnabled}
                label={t('alerts.rules.enable', { rule: rule.label })}
                disabled={disabled}
            />
            <div className="flex min-w-0 flex-[1_1_240px] flex-col gap-1">
                <span className="flex flex-wrap items-center gap-2 font-semibold">
                    {rule.label}
                    <span className="text-xs font-normal text-ink-3">
                        {enabled ? t('alerts.rules.on') : t('alerts.rules.off')}
                    </span>
                    {badge}
                </span>
                <span className="text-ink-2">{rule.description}</span>
            </div>
            <fieldset
                className="flex flex-[1_1_180px] flex-col gap-1.5"
                disabled={disabled}
            >
                <legend className="mb-1 text-xs text-ink-3">
                    {t('alerts.rules.channels')}
                </legend>
                {channels.length === 0 ? (
                    <span className="text-xs text-ink-3">
                        {t('alerts.rules.no_channels')}
                    </span>
                ) : (
                    <ChannelPicker
                        title={rule.label}
                        channels={channels}
                        selected={selected}
                        onChange={setSelected}
                        disabled={disabled}
                    />
                )}
                {!teamLevel &&
                    enabled &&
                    selected.length === 0 &&
                    channels.length > 0 && (
                        <p className="text-xs text-warn">
                            {t('alerts.rules.on_without_channel')}
                        </p>
                    )}
            </fieldset>
            {rule.settings ? (
                <fieldset
                    className="flex flex-[1_1_180px] flex-col gap-2"
                    disabled={disabled}
                >
                    <legend className="mb-1 text-xs text-ink-3">
                        {t('alerts.rules.thresholds.title')}
                    </legend>
                    {(['size', 'age_minutes', 'sustain_minutes'] as const).map(
                        (key) => (
                            <Field
                                key={key}
                                label={
                                    <span className="text-xs font-normal text-ink-3">
                                        {t(`alerts.rules.thresholds.${key}`)}
                                    </span>
                                }
                                htmlFor={`rule-${key}-${rule.id}`}
                            >
                                <Input
                                    id={`rule-${key}-${rule.id}`}
                                    type="number"
                                    min={1}
                                    className="font-mono"
                                    placeholder={
                                        key === 'sustain_minutes'
                                            ? '5'
                                            : t('alerts.rules.thresholds.off')
                                    }
                                    value={thresholds[key]}
                                    onChange={(e) =>
                                        setThresholds({
                                            ...thresholds,
                                            [key]: e.target.value,
                                        })
                                    }
                                />
                            </Field>
                        ),
                    )}
                    <p className="text-xs text-ink-3">
                        {t('alerts.rules.thresholds.hint')}
                    </p>
                </fieldset>
            ) : (
                <Field
                    label={
                        <span className="text-xs font-normal text-ink-3">
                            {t('alerts.rules.environments')}
                        </span>
                    }
                    htmlFor={`rule-env-${rule.id}`}
                    hint={t('alerts.rules.environments_hint')}
                    className="flex-[1_1_180px]"
                >
                    <Input
                        id={`rule-env-${rule.id}`}
                        className="font-mono"
                        placeholder={t('alerts.rules.environments_placeholder')}
                        value={environments}
                        disabled={disabled}
                        onChange={(e) => setEnvironments(e.target.value)}
                    />
                </Field>
            )}
            {!disabled && (dirty || firstError || actions) && (
                <div className="flex w-full flex-wrap items-center justify-end gap-3">
                    {firstError && (
                        <p className="text-xs text-err">{firstError}</p>
                    )}
                    {actions}
                    {dirty && (
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={processing}
                        >
                            {t('common.save')}
                            <span className="sr-only"> {rule.label}</span>
                        </Button>
                    )}
                </div>
            )}
        </form>
    );
}

/**
 * What a new-error message looks like where the channel delivers it. ntfy,
 * Pushover and webhooks have none.
 */
function ChannelPreview({ type, sender }: { type: string; sender: string }) {
    const t = useT();
    const preview = {
        mail: <MailPreview />,
        discord: <DiscordPreview sender={sender} />,
        slack: <SlackPreview />,
        teams: <TeamsPreview />,
        telegram: <TelegramPreview />,
    }[type];

    if (preview === undefined) {
        return null;
    }

    const title = t('alerts.preview.title', {
        app: type === 'mail' ? 'Gmail' : t(`alerts.types.${type}`),
    });

    return (
        <div
            // An inbox row needs the width to show its subject.
            className={cn(
                'flex min-w-0 flex-col gap-2',
                type === 'mail' && 'md:col-span-2',
            )}
            role="group"
            aria-label={title}
        >
            <span className="text-xs font-medium text-ink-3">{title}</span>
            {preview}
            <p className="text-xs text-ink-3">
                {type === 'discord'
                    ? t('alerts.preview.hint')
                    : t('alerts.preview.hint_general')}
            </p>
        </div>
    );
}

/** Location, release and occurrences: listed the same way by every chat message. */
function PreviewFacts({
    labelClass,
    valueClass,
    inline = false,
}: {
    labelClass: string;
    valueClass: string;
    inline?: boolean;
}) {
    const t = useT();
    const facts = [
        ['location', 'app/Actions/StoreOrderAction.php:88'],
        ['release', 'a1f3c9e'],
        ['occurrences', '1'],
    ];

    return (
        <dl
            className={cn(
                'grid gap-x-4 gap-y-1.5 text-xs',
                inline ? 'grid-cols-[auto_1fr]' : 'grid-cols-2',
            )}
        >
            {facts.map(([key, value]) => (
                <div
                    key={key}
                    className={cn(
                        inline ? 'contents' : 'flex flex-col',
                        key === 'location' && !inline && 'col-span-2',
                    )}
                >
                    <dt className={labelClass}>
                        {t(`alerts.preview.facts.${key}`)}
                    </dt>
                    <dd className={cn('font-mono break-all', valueClass)}>
                        {value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

const subject = (t: ReturnType<typeof useT>) =>
    `${t('alerts.preview.reference')} · ${t('alerts.preview.exception')}`;

/** The mail as a row in a Gmail inbox: sender, subject, the start of the text. */
function MailPreview() {
    const t = useT();

    return (
        <div className="flex flex-col overflow-hidden rounded-lg bg-white text-[13px] text-[#202124]">
            <div className="flex items-center gap-3 border-b border-[#ECEFF1] bg-[#F2F6FC] px-3 py-2.5">
                <span
                    aria-hidden="true"
                    className="size-3.5 shrink-0 rounded-sm border-2 border-[#BDC1C6]"
                />
                <span className="text-[#BDC1C6]" aria-hidden="true">
                    ☆
                </span>
                <strong className="w-24 shrink-0 truncate">Lumberjack</strong>
                <span className="min-w-0 flex-1 truncate">
                    <strong>
                        {t('alerts.preview.heading')}:{' '}
                        {t('alerts.preview.exception')}
                    </strong>
                    <span className="text-[#5F6368]">
                        {' '}
                        - {t('alerts.preview.message')}
                    </span>
                </span>
                <strong className="shrink-0 text-xs">10:12</strong>
            </div>
            <div className="flex items-center gap-3 px-3 py-2.5 text-[#5F6368]">
                <span
                    aria-hidden="true"
                    className="size-3.5 shrink-0 rounded-sm border-2 border-[#DADCE0]"
                />
                <span className="text-[#DADCE0]" aria-hidden="true">
                    ☆
                </span>
                <span className="w-24 shrink-0 truncate">
                    {t('alerts.preview.mail_other_sender')}
                </span>
                <span className="min-w-0 flex-1 truncate">
                    {t('alerts.preview.mail_other_subject')}
                </span>
                <span className="shrink-0 text-xs">09:40</span>
            </div>
        </div>
    );
}

function SlackPreview() {
    const t = useT();

    return (
        <div className="flex gap-2.5 rounded-lg bg-white p-3 text-[13px] text-[#1D1C1D]">
            <img
                src="/images/lumberjack-avatar.png"
                alt=""
                className="size-9 shrink-0 rounded"
            />
            <div className="flex min-w-0 flex-col gap-1">
                <span>
                    <strong>Lumberjack</strong>{' '}
                    <span className="rounded bg-[#E8E8E8] px-1 text-[10px] font-semibold text-[#616061]">
                        APP
                    </span>{' '}
                    <span className="text-[11px] text-[#616061]">10:12</span>
                </span>
                <strong className="text-[#1264A3]">
                    {t('alerts.preview.heading')}
                </strong>
                <code className="w-fit rounded border border-[#DDD] bg-[#F8F8F8] px-1 font-mono text-[12px] break-all text-[#E01E5A]">
                    {subject(t)}
                </code>
                <span>{t('alerts.preview.message')}</span>
                <PreviewFacts
                    labelClass="font-bold"
                    valueClass="text-[#1D1C1D]"
                />
                <span className="mt-1 w-fit rounded border border-[#BBBBBB] px-3 py-1 text-xs font-bold">
                    {t('alerts.preview.link')}
                </span>
            </div>
        </div>
    );
}

function TeamsPreview() {
    const t = useT();

    return (
        <div className="flex flex-col gap-2 rounded-lg bg-[#292929] p-3 text-[13px] text-[#FFFFFF]">
            <span className="text-[11px] text-[#ADADAD]">
                Workflows · 10:12
            </span>
            <div className="flex flex-col gap-1.5 rounded-md border border-[#3D3D3D] bg-[#1F1F1F] p-3">
                <strong className="text-[15px] text-[#F1707B]">
                    {t('alerts.preview.heading')}
                </strong>
                <span className="font-mono text-xs break-all">
                    {subject(t)}
                </span>
                <span className="text-[#ADADAD]">
                    {t('alerts.preview.message')}
                </span>
                <PreviewFacts
                    inline
                    labelClass="font-semibold"
                    valueClass="text-[#ADADAD]"
                />
                <span className="mt-1 w-fit rounded border border-[#5C5C5C] px-3 py-1 text-xs font-semibold">
                    {t('alerts.preview.link')}
                </span>
            </div>
        </div>
    );
}

function TelegramPreview() {
    const t = useT();

    return (
        <div className="rounded-lg bg-[#0E1621] p-3 text-[13px]">
            <div className="flex w-fit max-w-full flex-col gap-1 rounded-xl rounded-bl-sm bg-[#182533] px-3 py-2 text-[#F5F5F5]">
                <span className="text-xs font-semibold text-[#6AB2F2]">
                    Lumberjack
                </span>
                <strong>{t('alerts.preview.heading')}</strong>
                <code className="font-mono text-xs break-all text-[#E8A85C]">
                    {subject(t)}
                </code>
                <span>{t('alerts.preview.message')}</span>
                <PreviewFacts
                    inline
                    labelClass="font-bold"
                    valueClass="text-[#F5F5F5]"
                />
                <span className="text-[#6AB2F2] underline">
                    {t('alerts.preview.link')}
                </span>
                <span className="self-end text-[10px] text-[#6D7F8F]">
                    10:12
                </span>
            </div>
        </div>
    );
}

/** Discord, with the sender name being typed. */
function DiscordPreview({ sender }: { sender: string }) {
    const t = useT();

    return (
        <div className="flex max-w-[640px] gap-3 rounded-lg bg-[#313338] p-3.5">
            <img
                src="/images/lumberjack-avatar.png"
                alt=""
                className="size-9 shrink-0 rounded-full"
            />
            <div className="flex min-w-0 flex-col gap-1.5">
                <span>
                    <strong className="text-[#F2F3F5]">
                        {sender.trim() !== '' ? sender.trim() : 'Lumberjack'}
                    </strong>{' '}
                    <span className="rounded bg-[#5865F2] px-1 py-px text-[10px] font-semibold text-white">
                        APP
                    </span>{' '}
                    <span className="text-[11px] text-[#B5BAC1]">
                        {t('alerts.preview.time')}
                    </span>
                </span>
                <div className="flex flex-col gap-1.5 rounded border-l-4 border-l-err bg-[#2B2D31] px-3 py-2.5">
                    <span className="font-semibold text-[#F2F3F5]">
                        {t('alerts.preview.heading')}
                    </span>
                    <span className="font-mono text-xs break-all text-[#DBDEE1]">
                        {subject(t)}
                    </span>
                    <span className="text-[#DBDEE1]">
                        {t('alerts.preview.message')}
                    </span>
                    <PreviewFacts
                        labelClass="font-semibold text-[#F2F3F5]"
                        valueClass="text-[#DBDEE1]"
                    />
                    <span className="text-[13px] text-[#00A8FC] underline">
                        {t('alerts.preview.link')}
                    </span>
                </div>
            </div>
        </div>
    );
}
