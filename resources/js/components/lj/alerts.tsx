import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmButton } from '@/components/lj/confirm-button';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    EmptyState,
    Field,
    Input,
    Select,
} from '@/components/lj/ui';
import { formatRelative } from '@/lib/format';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    destroy as channelDestroy,
    store as channelStore,
    test as channelTest,
    update as channelUpdate,
} from '@/routes/alerts/channels';
import { update as ruleUpdate } from '@/routes/alerts/rules';

export type Channel = {
    id: number;
    type: string;
    name: string;
    target: string;
    last_delivery_status: string | null;
    last_delivery_at: string | null;
    settings?: Record<string, string>;
};
export type Rule = {
    id: number;
    type: string;
    label: string;
    description: string;
    enabled: boolean;
    channels: number[];
    environments: string[];
};

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
 * Alert channels and the form to add one. With a project, they are that project's
 * own channels; without, the team's shared ones.
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
    const [type, setType] = useState(types[0] ?? 'discord');
    const [editing, setEditing] = useState<number | null>(null);
    const [newSender, setNewSender] = useState('');
    const [adding, setAdding] = useState(false);
    const field = configField[type] ?? {
        key: 'address',
        type: 'text',
        placeholder: '',
    };

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
                                className="flex flex-col gap-3 rounded-md border border-line px-3 py-2.5"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3">
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
                                                    aria-expanded={
                                                        editing === channel.id
                                                    }
                                                    onClick={() =>
                                                        setEditing(
                                                            editing ===
                                                                channel.id
                                                                ? null
                                                                : channel.id,
                                                        )
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
                                                            channelTest(
                                                                channel.id,
                                                            ).url,
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
                                                        {
                                                            name: channel.name,
                                                        },
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
                                </div>
                                {editing === channel.id && (
                                    <ChannelEditForm
                                        channel={channel}
                                        onDone={() => setEditing(null)}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {canAdminister && !adding && (
                    <div>
                        <Button onClick={() => setAdding(true)}>
                            {t('alerts.channels.add_new')}
                        </Button>
                    </div>
                )}

                {canAdminister && adding && (
                    <Form
                        {...channelStore.form()}
                        options={{ preserveScroll: true }}
                        transform={(data) =>
                            project ? { ...data, project } : data
                        }
                        resetOnSuccess
                        onSuccess={() => {
                            setAdding(false);
                            setNewSender('');
                        }}
                        className="flex flex-wrap items-start gap-2 border-t border-line pt-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    label={t('alerts.channels.type')}
                                    htmlFor="channel-type"
                                    error={errors.type}
                                    className="flex-[0_1_140px]"
                                >
                                    <Select
                                        id="channel-type"
                                        name="type"
                                        value={type}
                                        onChange={(e) =>
                                            setType(e.target.value)
                                        }
                                    >
                                        {types.map((value) => (
                                            <option key={value} value={value}>
                                                {t(`alerts.types.${value}`)}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field
                                    label={t('alerts.channels.name')}
                                    htmlFor="channel-name"
                                    error={errors.name}
                                    className="min-w-0 flex-[1_1_180px]"
                                >
                                    <Input
                                        id="channel-name"
                                        name="name"
                                        required
                                        maxLength={100}
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
                                    error={
                                        (errors as Record<string, string>)[
                                            `config.${field.key}`
                                        ] ?? errors.config
                                    }
                                    className="min-w-0 flex-[2_1_280px]"
                                >
                                    <Input
                                        key={field.key}
                                        id="channel-config"
                                        name={`config[${field.key}]`}
                                        type={field.type}
                                        required
                                        className="font-mono"
                                        placeholder={field.placeholder}
                                        autoComplete="off"
                                    />
                                </Field>
                                <ExtraFields
                                    key={type}
                                    type={type}
                                    idPrefix="channel"
                                    errors={errors as Record<string, string>}
                                    onChange={(_, value) => setNewSender(value)}
                                />
                                <Button
                                    type="submit"
                                    variant="primary"
                                    disabled={processing}
                                    className="mt-6"
                                >
                                    {t('alerts.channels.add')}
                                </Button>
                                <Button
                                    onClick={() => setAdding(false)}
                                    className="mt-6"
                                >
                                    {t('common.cancel')}
                                </Button>
                                {type === 'discord' && (
                                    <DiscordPreview sender={newSender} />
                                )}
                            </>
                        )}
                    </Form>
                )}
            </div>
        </Card>
    );
}

/**
 * A project's alert rules: which kinds of alert go to which channels.
 */
export function RulesCard({
    rules,
    channels,
    canAdminister,
}: {
    rules: Rule[];
    channels: Channel[];
    canAdminister: boolean;
}) {
    const t = useT();

    return (
        <Card aria-labelledby="h-rules">
            <CardHeader id="h-rules" title={t('alerts.rules.title')} />
            <div className="flex flex-col gap-3 p-4">
                {rules.length === 0 ? (
                    <EmptyState title={t('alerts.rules.empty')} />
                ) : (
                    rules.map((rule) => (
                        <RuleRow
                            key={rule.id}
                            rule={rule}
                            channels={channels}
                            disabled={!canAdminister}
                        />
                    ))
                )}
                <p className="text-xs text-ink-3">{t('alerts.rules.hint')}</p>
            </div>
        </Card>
    );
}

function ChannelEditForm({
    channel,
    onDone,
}: {
    channel: Channel;
    onDone: () => void;
}) {
    const t = useT();
    const [sender, setSender] = useState(channel.settings?.sender_name ?? '');
    const field = configField[channel.type] ?? {
        key: 'address',
        type: 'text',
        placeholder: '',
    };

    return (
        <Form
            {...channelUpdate.form(channel.id)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="flex flex-wrap items-start gap-2 border-t border-line pt-3"
        >
            {({ errors, processing }) => (
                <>
                    <input type="hidden" name="type" value={channel.type} />
                    <Field
                        label={t('alerts.channels.name')}
                        htmlFor={`edit-name-${channel.id}`}
                        error={errors.name}
                        className="min-w-0 flex-[1_1_180px]"
                    >
                        <Input
                            id={`edit-name-${channel.id}`}
                            name="name"
                            required
                            maxLength={100}
                            defaultValue={channel.name}
                            autoComplete="off"
                        />
                    </Field>
                    <Field
                        label={t(`alerts.channels.config.${field.key}`)}
                        htmlFor={`edit-config-${channel.id}`}
                        hint={t('alerts.channels.keep_hint')}
                        error={
                            (errors as Record<string, string>)[
                                `config.${field.key}`
                            ] ?? errors.config
                        }
                        className="min-w-0 flex-[2_1_280px]"
                    >
                        <Input
                            id={`edit-config-${channel.id}`}
                            name={`config[${field.key}]`}
                            type={field.type}
                            className="font-mono"
                            placeholder={channel.target}
                            autoComplete="off"
                        />
                    </Field>
                    <ExtraFields
                        type={channel.type}
                        idPrefix={`edit-${channel.id}`}
                        errors={errors as Record<string, string>}
                        values={channel.settings}
                        onChange={(_, value) => setSender(value)}
                    />
                    <div className="mt-6 flex gap-2">
                        <Button onClick={onDone}>{t('common.cancel')}</Button>
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={processing}
                        >
                            {t('common.save')}
                        </Button>
                    </div>
                    {channel.type === 'discord' && (
                        <DiscordPreview sender={sender} />
                    )}
                </>
            )}
        </Form>
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
                    {formatRelative(channel.last_delivery_at)}
                </span>
            )}
        </Badge>
    );
}

function RuleRow({
    rule,
    channels,
    disabled,
}: {
    rule: Rule;
    channels: Channel[];
    disabled: boolean;
}) {
    const t = useT();
    const [enabled, setEnabled] = useState(rule.enabled);
    const [selected, setSelected] = useState<number[]>(rule.channels);
    const [environments, setEnvironments] = useState(
        rule.environments.join(', '),
    );
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

    return (
        <form
            className="flex flex-wrap items-start gap-4 rounded-lg border border-line bg-rail p-3.5"
            onSubmit={(e) => {
                e.preventDefault();
                save();
            }}
        >
            <button
                type="button"
                role="switch"
                aria-checked={enabled}
                aria-label={t('alerts.rules.enable', { rule: rule.label })}
                disabled={disabled}
                onClick={() => setEnabled(!enabled)}
                className={cn(
                    'relative mt-0.5 h-5 w-9 shrink-0 cursor-pointer rounded-full border transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber disabled:cursor-not-allowed disabled:opacity-50',
                    enabled
                        ? 'border-amber bg-amber'
                        : 'border-line-strong bg-panel-2',
                )}
            >
                <span
                    aria-hidden="true"
                    className={cn(
                        'absolute top-0.5 size-3.5 rounded-full bg-ink transition-all',
                        enabled ? 'left-[18px]' : 'left-0.5',
                    )}
                />
            </button>
            <div className="flex min-w-0 flex-[1_1_240px] flex-col gap-1">
                <span className="font-semibold">
                    {rule.label}{' '}
                    <span className="text-xs font-normal text-ink-3">
                        {enabled ? t('alerts.rules.on') : t('alerts.rules.off')}
                    </span>
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
                    channels.map((channel) => (
                        <label
                            key={channel.id}
                            className="inline-flex items-center gap-2"
                        >
                            <input
                                type="checkbox"
                                className="size-4 accent-amber"
                                checked={selected.includes(channel.id)}
                                onChange={(e) =>
                                    setSelected(
                                        e.target.checked
                                            ? [...selected, channel.id]
                                            : selected.filter(
                                                  (id) => id !== channel.id,
                                              ),
                                    )
                                }
                            />
                            <span className="text-ink-3">
                                {t(`alerts.types.${channel.type}`)}:
                            </span>
                            {channel.name}
                        </label>
                    ))
                )}
            </fieldset>
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
                    placeholder="production"
                    value={environments}
                    disabled={disabled}
                    onChange={(e) => setEnvironments(e.target.value)}
                />
            </Field>
            {!disabled && (
                <div className="flex w-full flex-wrap items-center justify-end gap-3">
                    {firstError && (
                        <p className="text-xs text-err">{firstError}</p>
                    )}
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={processing}
                    >
                        {t('common.save')}
                        <span className="sr-only"> {rule.label}</span>
                    </Button>
                </div>
            )}
        </form>
    );
}

/**
 * What a new-error message looks like in Discord, from the sender name being typed.
 */
function DiscordPreview({ sender }: { sender: string }) {
    const t = useT();

    return (
        <div
            className="flex w-full flex-col gap-2"
            role="group"
            aria-label={t('alerts.preview.title')}
        >
            <span className="text-xs font-medium text-ink-3">
                {t('alerts.preview.title')}
            </span>
            <div className="flex max-w-[640px] gap-3 rounded-lg bg-[#313338] p-3.5">
                <img
                    src="/images/lumberjack-avatar.png"
                    alt=""
                    className="size-9 shrink-0 rounded-full"
                />
                <div className="flex min-w-0 flex-col gap-1.5">
                    <span>
                        <strong className="text-[#F2F3F5]">
                            {sender.trim() !== ''
                                ? sender.trim()
                                : 'Lumberjack'}
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
                            {t('alerts.preview.exception')}
                        </span>
                        <span className="text-[#DBDEE1]">
                            {t('alerts.preview.message')}
                        </span>
                        <span className="text-xs text-[#B5BAC1]">
                            {t('alerts.preview.meta')}
                        </span>
                        <span className="text-[13px] text-[#00A8FC] underline">
                            {t('alerts.preview.link')}
                        </span>
                    </div>
                </div>
            </div>
            <p className="text-xs text-ink-3">{t('alerts.preview.hint')}</p>
        </div>
    );
}
