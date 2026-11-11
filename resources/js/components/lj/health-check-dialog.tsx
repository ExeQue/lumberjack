import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { TreeSelect } from '@/components/lj/tree-select';
import { Button, Field, Input, Select, Switch } from '@/components/lj/ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import { store as healthStore, update as healthUpdate } from '@/routes/health';
import type { HealthCheckRow, HealthCheckType } from '@/types/lumberjack';

/** A data source a check can read: JSON or Prometheus at a path, or a connected one's number over a period. */
export type HealthDataSource = {
    uuid: string;
    name: string;
    group: string | null;
    format: 'json' | 'prometheus' | 'connected';
    environment: string | null;
    /** A service's name or an app's host, at the top of the picker. */
    host: string | null;
    /** The periods a connected source answers for; null for a fetched one. */
    periods: string[] | null;
};

type ConfigField = {
    key: string;
    type: 'text' | 'url' | 'number' | 'select' | 'source';
    required?: boolean;
    placeholder?: string;
    options?: string[];
    hint?: boolean;
    decimal?: boolean;
    /** The translation key the options are named under; without it they show as they are. */
    optionLabels?: string;
    /** Only for a fetched source (a path), or only for a connected one (a period). */
    only?: 'fetched' | 'connected';
};

// The settings each type takes, in the order the form shows them.
const configFields: Record<HealthCheckType, ConfigField[]> = {
    http: [
        {
            key: 'url',
            type: 'url',
            required: true,
            placeholder: 'https://example.com/up',
        },
        { key: 'method', type: 'select', options: ['GET', 'HEAD', 'POST'] },
        { key: 'expected_status', type: 'number', placeholder: '200' },
        { key: 'body_contains', type: 'text', hint: true },
        { key: 'json_path', type: 'text', hint: true, placeholder: 'status' },
        { key: 'json_expected', type: 'text', hint: true, placeholder: 'ok' },
        { key: 'max_latency_ms', type: 'number', placeholder: '1000' },
        { key: 'timeout_s', type: 'number', placeholder: '10' },
    ],
    ssl: [
        {
            key: 'host',
            type: 'text',
            required: true,
            placeholder: 'example.com',
        },
        { key: 'port', type: 'number', placeholder: '443' },
        { key: 'warn_days', type: 'number', placeholder: '14' },
        { key: 'fail_days', type: 'number', placeholder: '3' },
    ],
    tcp: [
        {
            key: 'host',
            type: 'text',
            required: true,
            placeholder: 'db.example.com',
        },
        { key: 'port', type: 'number', required: true, placeholder: '5432' },
        { key: 'timeout_s', type: 'number', placeholder: '5' },
    ],
    dns: [
        {
            key: 'name',
            type: 'text',
            required: true,
            placeholder: 'example.com',
        },
        {
            key: 'record_type',
            type: 'select',
            options: ['A', 'AAAA', 'CNAME', 'MX', 'TXT'],
        },
        { key: 'expected', type: 'text', hint: true },
    ],
    heartbeat: [
        { key: 'grace_minutes', type: 'number', placeholder: '5', hint: true },
    ],
    domain: [
        {
            key: 'domain',
            type: 'text',
            required: true,
            hint: true,
            placeholder: 'example.com',
        },
        { key: 'warn_days', type: 'number', placeholder: '30' },
        { key: 'fail_days', type: 'number', placeholder: '7' },
    ],
    smtp: [
        {
            key: 'host',
            type: 'text',
            required: true,
            placeholder: 'mail.example.com',
        },
        { key: 'port', type: 'number', required: true, placeholder: '587' },
        {
            key: 'encryption',
            type: 'select',
            options: ['STARTTLS', 'TLS', 'None'],
        },
        { key: 'timeout_s', type: 'number', placeholder: '10' },
    ],
    data_source: [
        { key: 'data_source', type: 'source', required: true },
        {
            key: 'path',
            type: 'text',
            required: true,
            hint: true,
            placeholder: 'queue.size',
            only: 'fetched',
        },
        {
            key: 'period',
            type: 'select',
            hint: true,
            optionLabels: 'common.periods',
            only: 'connected',
        },
        {
            key: 'operator',
            type: 'select',
            options: ['>', '>=', '<', '<=', '=', '!='],
        },
        { key: 'warn', type: 'number', hint: true, decimal: true },
        { key: 'fail', type: 'number', hint: true, decimal: true },
    ],
};

/**
 * Adding a check Lumberjack runs (pick the type, then its settings), or editing one;
 * a reported check only takes how it is shown and alerted on.
 */
export function HealthCheckDialog({
    project,
    check,
    types,
    intervals,
    dataSources,
    onClose,
}: {
    project: string;
    check?: HealthCheckRow;
    types: HealthCheckType[];
    intervals: number[];
    dataSources: HealthDataSource[];
    onClose: () => void;
}) {
    const t = useT();
    const [type, setType] = useState<HealthCheckType | null>(
        check?.type ?? null,
    );
    const [muted, setMuted] = useState(check?.muted ?? false);
    // One no longer offered (archived or deleted) starts empty, so it is picked again.
    const [source, setSource] = useState(() => {
        const saved = String(check?.config?.data_source ?? '');

        return dataSources.some((s) => s.uuid === saved) ? saved : '';
    });
    const picked = dataSources.find((s) => s.uuid === source);
    const prometheus = picked?.format === 'prometheus';
    const connected = picked?.format === 'connected';
    // A period select offers what the picked source answers for.
    const fieldsOf = (fields: ConfigField[]) =>
        fields
            .filter(
                (field) =>
                    field.only === undefined ||
                    field.only === (connected ? 'connected' : 'fetched'),
            )
            .map((field) =>
                field.key === 'period'
                    ? { ...field, options: picked?.periods ?? [] }
                    : field,
            );
    const reported = check?.source === 'reported';
    const form = check
        ? healthUpdate.form([project, check.uuid])
        : healthStore.form(project);
    // A reported check's thresholds are empty unless set here, so the app's apply.
    const threshold = (value: number | undefined) =>
        reported && !check?.thresholds_overridden ? undefined : value;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {check
                            ? t('health.form.edit_title', { check: check.name })
                            : t('health.form.add_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {reported
                            ? t('health.form.reported_setup')
                            : type === null
                              ? t('health.form.pick_type')
                              : t('health.form.setup')}
                    </DialogDescription>
                </DialogHeader>

                {!reported && type === null ? (
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-2">
                        {types.map((value) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setType(value)}
                                className="flex flex-col gap-1 rounded-md border border-line bg-panel px-3 py-2.5 text-left hover:border-amber"
                            >
                                <span className="font-medium text-ink">
                                    {t(`health.types.${value}`)}
                                </span>
                                <span className="text-xs text-ink-3">
                                    {t(`health.type_about.${value}`)}
                                </span>
                            </button>
                        ))}
                    </div>
                ) : (
                    <Form
                        {...form}
                        options={{ preserveScroll: true }}
                        transform={(data) => ({
                            ...data,
                            muted,
                            ...(!check && { type }),
                        })}
                        onSuccess={onClose}
                        className="flex flex-col gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field
                                        label={t('health.form.name')}
                                        htmlFor="health-name"
                                        error={errors.name}
                                    >
                                        <Input
                                            id="health-name"
                                            name="name"
                                            required={
                                                type === 'heartbeat' ||
                                                type === 'data_source'
                                            }
                                            autoFocus
                                            maxLength={150}
                                            defaultValue={
                                                check?.custom_name ?? ''
                                            }
                                            placeholder={
                                                check?.name ??
                                                t(
                                                    type === 'heartbeat'
                                                        ? 'health.form.name_placeholder'
                                                        : type === 'data_source'
                                                          ? 'health.form.name_placeholder_data_source'
                                                          : 'health.form.name_from_settings',
                                                )
                                            }
                                            autoComplete="off"
                                        />
                                    </Field>
                                    {!reported && (
                                        <Field
                                            label={t('health.form.environment')}
                                            htmlFor="health-environment"
                                            hint={t(
                                                'health.form.environment_hint',
                                            )}
                                            error={errors.environment}
                                        >
                                            <Input
                                                id="health-environment"
                                                name="environment"
                                                maxLength={64}
                                                className="font-mono"
                                                defaultValue={
                                                    check?.environment ?? ''
                                                }
                                                placeholder="production"
                                            />
                                        </Field>
                                    )}
                                    <Field
                                        label={t('health.form.description')}
                                        htmlFor="health-description"
                                        error={errors.description}
                                        className="sm:col-span-2"
                                    >
                                        <Input
                                            id="health-description"
                                            name="description"
                                            maxLength={1000}
                                            defaultValue={
                                                check?.description ?? ''
                                            }
                                        />
                                    </Field>
                                </div>

                                {!reported && type !== null && (
                                    <fieldset className="grid gap-3 rounded-md border border-line p-3 sm:grid-cols-2">
                                        <legend className="px-1 text-xs text-ink-3">
                                            {t(`health.types.${type}`)}
                                        </legend>
                                        {fieldsOf(configFields[type]).map(
                                            (field) => (
                                                <ConfigInput
                                                    // A period's options follow the source picked.
                                                    key={
                                                        field.key === 'period'
                                                            ? `period-${source}`
                                                            : field.key
                                                    }
                                                    field={field}
                                                    source={
                                                        field.type === 'source'
                                                            ? {
                                                                  options:
                                                                      dataSources,
                                                                  value: source,
                                                                  onChange:
                                                                      setSource,
                                                              }
                                                            : undefined
                                                    }
                                                    placeholder={
                                                        field.key === 'path' &&
                                                        prometheus
                                                            ? 'queue_size{queue="default"}'
                                                            : field.placeholder
                                                    }
                                                    value={
                                                        check?.config?.[
                                                            field.key
                                                        ]
                                                    }
                                                    error={
                                                        (
                                                            errors as Record<
                                                                string,
                                                                string
                                                            >
                                                        )[`config.${field.key}`]
                                                    }
                                                />
                                            ),
                                        )}
                                    </fieldset>
                                )}

                                <div className="grid gap-3 sm:grid-cols-2">
                                    {!reported && (
                                        <Field
                                            label={t('health.form.interval')}
                                            htmlFor="health-interval"
                                            error={errors.interval_minutes}
                                        >
                                            <Select
                                                id="health-interval"
                                                name="interval_minutes"
                                                defaultValue={
                                                    check?.interval_minutes ?? 5
                                                }
                                            >
                                                {intervals.map((minutes) => (
                                                    <option
                                                        key={minutes}
                                                        value={minutes}
                                                    >
                                                        {t(
                                                            'health.form.interval_option',
                                                            {},
                                                            minutes,
                                                        )}
                                                    </option>
                                                ))}
                                            </Select>
                                        </Field>
                                    )}
                                    <Field
                                        label={t('health.form.degrade_after')}
                                        htmlFor="health-degrade"
                                        hint={t('health.form.results_unit')}
                                        error={errors.degrade_after}
                                    >
                                        <Input
                                            id="health-degrade"
                                            name="degrade_after"
                                            type="number"
                                            min={1}
                                            max={100}
                                            required={!reported}
                                            className="font-mono"
                                            defaultValue={threshold(
                                                check?.degrade_after ?? 2,
                                            )}
                                            placeholder={
                                                reported
                                                    ? String(
                                                          check?.degrade_after,
                                                      )
                                                    : '2'
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label={t('health.form.down_after')}
                                        htmlFor="health-down"
                                        hint={t('health.form.down_unit')}
                                        error={errors.down_after}
                                    >
                                        <Input
                                            id="health-down"
                                            name="down_after"
                                            type="number"
                                            min={1}
                                            max={100}
                                            required={!reported}
                                            className="font-mono"
                                            defaultValue={threshold(
                                                check?.down_after ?? 5,
                                            )}
                                            placeholder={
                                                reported
                                                    ? String(check?.down_after)
                                                    : '5'
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label={t('health.form.recover_after')}
                                        htmlFor="health-recover"
                                        hint={t('health.form.results_unit')}
                                        error={errors.recover_after}
                                    >
                                        <Input
                                            id="health-recover"
                                            name="recover_after"
                                            type="number"
                                            min={1}
                                            max={100}
                                            required={!reported}
                                            className="font-mono"
                                            defaultValue={threshold(
                                                check?.recover_after ?? 5,
                                            )}
                                            placeholder={
                                                reported
                                                    ? String(
                                                          check?.recover_after,
                                                      )
                                                    : '5'
                                            }
                                        />
                                    </Field>
                                </div>
                                {reported && (
                                    <p className="-mt-2 text-xs text-ink-3">
                                        {t(
                                            'health.form.thresholds_hint_reported',
                                        )}
                                    </p>
                                )}

                                <label className="inline-flex items-center gap-2">
                                    <Switch
                                        small
                                        checked={muted}
                                        onChange={setMuted}
                                        label={t('health.form.muted')}
                                    />
                                    {t('health.form.muted')}
                                </label>

                                <DialogFooter>
                                    {!check && (
                                        <Button
                                            onClick={() => setType(null)}
                                            className="sm:mr-auto"
                                        >
                                            {t('health.form.back')}
                                        </Button>
                                    )}
                                    <Button onClick={onClose}>
                                        {t('common.cancel')}
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        disabled={
                                            processing ||
                                            // The picker is no form field the browser can require.
                                            (type === 'data_source' &&
                                                source === '')
                                        }
                                    >
                                        {check
                                            ? t('health.form.save')
                                            : t('health.form.add')}
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

function ConfigInput({
    field,
    source,
    placeholder,
    value,
    error,
}: {
    field: ConfigField;
    source?: {
        options: HealthDataSource[];
        value: string;
        onChange: (uuid: string) => void;
    };
    placeholder?: string;
    value: string | number | undefined;
    error?: string;
}) {
    const t = useT();
    const id = `health-config-${field.key}`;
    const wide = field.type === 'url' || field.type === 'source';

    return (
        <Field
            label={t(`health.form.config.${field.key}`)}
            htmlFor={id}
            hint={
                field.hint
                    ? t(`health.form.config.${field.key}_hint`)
                    : undefined
            }
            error={error}
            className={wide ? 'sm:col-span-2' : undefined}
        >
            {source ? (
                <>
                    <DataSourcePicker
                        id={id}
                        name={`config[${field.key}]`}
                        {...source}
                    />
                    {source.options.length === 0 && (
                        <span className="text-xs text-ink-3">
                            {t('health.form.config.data_source_empty')}
                        </span>
                    )}
                </>
            ) : field.type === 'select' ? (
                <Select
                    id={id}
                    name={`config[${field.key}]`}
                    defaultValue={value ?? field.options?.[0]}
                >
                    {field.options?.map((option) => (
                        <option key={option} value={option}>
                            {field.optionLabels
                                ? t(`${field.optionLabels}.${option}`)
                                : option}
                        </option>
                    ))}
                </Select>
            ) : (
                <Input
                    id={id}
                    name={`config[${field.key}]`}
                    type={field.type}
                    step={field.decimal ? 'any' : undefined}
                    required={field.required}
                    className="font-mono"
                    defaultValue={value ?? ''}
                    placeholder={placeholder}
                    autoComplete="off"
                />
            )}
        </Field>
    );
}

/**
 * The data sources to read, searchable and filed under their groups as in the screen
 * designer; named with their environment when they do not all share one.
 */
function DataSourcePicker({
    id,
    name,
    options,
    value,
    onChange,
}: {
    id: string;
    name: string;
    options: HealthDataSource[];
    value: string;
    onChange: (uuid: string) => void;
}) {
    const t = useT();
    const oneEnvironment = new Set(options.map((s) => s.environment)).size <= 1;

    return (
        <TreeSelect
            id={id}
            name={name}
            label={t('health.form.config.data_source')}
            options={options.map((s) => ({
                value: s.uuid,
                label:
                    s.environment && !oneEnvironment
                        ? `${s.name} (${s.environment})`
                        : s.name,
                path: [
                    ...(s.host ? [s.host] : []),
                    ...(s.group ? s.group.split(' > ') : []),
                ],
            }))}
            value={value}
            onChange={(uuid) => onChange(uuid ?? '')}
            placeholder={t('health.form.config.data_source_none')}
            searchPlaceholder={t('health.form.config.data_source_search')}
            noMatches={t('health.form.config.data_source_no_matches')}
        />
    );
}
