import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Field, Input, Select } from '@/components/lj/ui';
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

type ConfigField = {
    key: string;
    type: 'text' | 'url' | 'number' | 'select';
    required?: boolean;
    placeholder?: string;
    options?: string[];
    hint?: boolean;
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
    onClose,
}: {
    project: string;
    check?: HealthCheckRow;
    types: HealthCheckType[];
    intervals: number[];
    onClose: () => void;
}) {
    const t = useT();
    const [type, setType] = useState<HealthCheckType | null>(
        check?.type ?? null,
    );
    const [muted, setMuted] = useState(check?.muted ?? false);
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
                                            required={!reported}
                                            autoFocus
                                            maxLength={150}
                                            defaultValue={
                                                check?.custom_name ?? ''
                                            }
                                            placeholder={
                                                check?.slug ??
                                                t(
                                                    'health.form.name_placeholder',
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
                                        {configFields[type].map((field) => (
                                            <ConfigInput
                                                key={field.key}
                                                field={field}
                                                value={
                                                    check?.config?.[field.key]
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
                                        ))}
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
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-amber"
                                        checked={muted}
                                        onChange={(e) =>
                                            setMuted(e.target.checked)
                                        }
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
                                        disabled={processing}
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
    value,
    error,
}: {
    field: ConfigField;
    value: string | number | undefined;
    error?: string;
}) {
    const t = useT();
    const id = `health-config-${field.key}`;
    const wide = field.type === 'url';

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
            {field.type === 'select' ? (
                <Select
                    id={id}
                    name={`config[${field.key}]`}
                    defaultValue={value ?? field.options?.[0]}
                >
                    {field.options?.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                </Select>
            ) : (
                <Input
                    id={id}
                    name={`config[${field.key}]`}
                    type={field.type}
                    required={field.required}
                    className="font-mono"
                    defaultValue={value ?? ''}
                    placeholder={field.placeholder}
                    autoComplete="off"
                />
            )}
        </Field>
    );
}
