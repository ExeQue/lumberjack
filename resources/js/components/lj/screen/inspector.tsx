import type { ReactNode } from 'react';
import { Button, Input, Label, Select } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { option } from './lib';
import type {
    Schema,
    SchemaField,
    Screen,
    Sources,
    Widget,
    WidgetScope,
} from './types';
import { ROTATING_KINDS } from './types';

export type Catalog = {
    schema: Schema;
    sources: Sources;
    projects: { uuid: string; name: string }[];
    environments: string[];
    checks: { uuid: string; name: string; project: string | null }[];
    periods: string[];
};

const ALL = '*';

/** Every time zone the browser knows, plus the one already set. */
function timezones(current?: string): string[] {
    const zones = Intl.supportedValuesOf('timeZone');

    return current && !zones.includes(current) ? [current, ...zones] : zones;
}

const chip = (on: boolean) =>
    cn(
        'rounded-full border px-2.5 py-1 text-xs',
        on
            ? 'border-amber bg-amber/15 text-amber'
            : 'border-line-strong bg-rail text-ink-2 hover:text-ink',
    );

function Section({
    title,
    open,
    children,
}: {
    title: string;
    open: boolean;
    children: ReactNode;
}) {
    return (
        <details open={open} className="border-t border-line pt-2.5">
            <summary className="text-[11px] font-semibold tracking-wide text-ink-3 uppercase">
                {title}
            </summary>
            <div className="grid grid-cols-2 gap-2.5 pt-2.5">{children}</div>
        </details>
    );
}

/** The selected widget's settings, drawn from its type's option schema. */
export function WidgetInspector({
    widget,
    catalog,
    onChange,
    onRemove,
    cols,
}: {
    widget: Widget;
    catalog: Catalog;
    onChange: (widget: Widget) => void;
    onRemove: () => void;
    cols: number;
}) {
    const t = useT();
    const { schema, sources } = catalog;
    const pool = sources[widget.type] ?? [];
    const source = pool.find((s) => s.id === widget.source);
    const id = (key: string) => `w-${widget.id}-${key}`;

    const setOption = (key: string, value: unknown) => {
        const options = { ...widget.options };

        if (value === '' || value === null || value === undefined) {
            delete options[key];
        } else {
            options[key] = value;
        }

        onChange({ ...widget, options });
    };

    const setScope = (key: keyof WidgetScope, value: unknown) => {
        const scope = { ...widget.scope } as Record<string, unknown>;

        if (value === '' || value === null || value === undefined) {
            delete scope[key];
        } else {
            scope[key] = value;
        }

        onChange({ ...widget, scope: scope as WidgetScope });
    };

    const pickSource = (next: string) =>
        onChange({
            ...widget,
            source: next,
            title: null,
            rotate: widget.rotate.filter((r) => r !== next),
            // The new source's own limits and scale apply.
            options: Object.fromEntries(
                Object.entries(widget.options).filter(
                    ([key]) =>
                        !['amber', 'red', 'low_is_bad', 'min', 'max'].includes(
                            key,
                        ),
                ),
            ),
        });

    const fieldLabel = (key: string) => t(`screens.fields.${key}.label`);

    const renderField = (field: SchemaField, scope: boolean): ReactNode => {
        const span = field.span === 2 ? 'col-span-2' : '';
        const raw = (
            scope
                ? (widget.scope as Record<string, unknown>)[field.key]
                : widget.options[field.key]
        ) as string | number | string[] | undefined;

        switch (field.type) {
            case 'select':
                return (
                    <div
                        key={field.key}
                        className={cn('flex flex-col gap-1', span)}
                    >
                        <Label
                            htmlFor={id(field.key)}
                            className="text-xs text-ink-2"
                        >
                            {fieldLabel(field.key)}
                        </Label>
                        <Select
                            id={id(field.key)}
                            value={String(
                                raw ?? (scope ? '' : (field.default ?? '')),
                            )}
                            onChange={(e) =>
                                scope
                                    ? setScope(
                                          field.key as keyof WidgetScope,
                                          e.target.value,
                                      )
                                    : setOption(
                                          field.key,
                                          e.target.value === field.default
                                              ? null
                                              : e.target.value,
                                      )
                            }
                        >
                            {scope && field.key === 'period' && (
                                <option value="">
                                    {t('screens.inspector.screen_default')}
                                </option>
                            )}
                            {(field.options ?? []).map((value) => (
                                <option key={value} value={value}>
                                    {field.key === 'period'
                                        ? t(`screens.periods.${value}`)
                                        : t(
                                              `screens.fields.${field.key}.options.${value}`,
                                          )}
                                </option>
                            ))}
                        </Select>
                    </div>
                );
            case 'toggle': {
                const effective =
                    field.key === 'low_is_bad'
                        ? ((raw as boolean | undefined) ??
                          source?.low_is_bad ??
                          false)
                        : option<boolean>(schema, widget, field.key) === true;

                return (
                    <label
                        key={field.key}
                        className="col-span-2 flex items-center gap-2 text-[13px] text-ink-2"
                    >
                        <input
                            type="checkbox"
                            checked={effective}
                            onChange={() =>
                                setOption(
                                    field.key,
                                    field.key === 'low_is_bad' ||
                                        !effective !== field.default
                                        ? !effective
                                        : null,
                                )
                            }
                        />
                        {fieldLabel(field.key)}
                    </label>
                );
            }
            case 'number': {
                const fallback =
                    field.key === 'amber' || field.key === 'red'
                        ? source?.[field.key]
                        : null;

                return (
                    <div
                        key={field.key}
                        className={cn('flex flex-col gap-1', span)}
                    >
                        <Label
                            htmlFor={id(field.key)}
                            className={cn(
                                'text-xs',
                                field.key === 'amber'
                                    ? 'text-amber'
                                    : field.key === 'red'
                                      ? 'text-err'
                                      : 'text-ink-2',
                            )}
                        >
                            {fieldLabel(field.key)}
                        </Label>
                        <Input
                            id={id(field.key)}
                            type="number"
                            step="any"
                            value={
                                raw === undefined || raw === null
                                    ? ''
                                    : String(raw)
                            }
                            placeholder={
                                fallback !== null && fallback !== undefined
                                    ? String(fallback)
                                    : t('screens.inspector.auto')
                            }
                            onChange={(e) =>
                                setOption(
                                    field.key,
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                                )
                            }
                        />
                    </div>
                );
            }
            case 'checks': {
                const picked = (raw as string[] | undefined) ?? [];

                return (
                    <div
                        key={field.key}
                        className="col-span-2 flex flex-col gap-1.5"
                    >
                        <span className="text-xs text-ink-2">
                            {fieldLabel(field.key)}
                        </span>
                        <div className="flex flex-wrap gap-1.5">
                            {catalog.checks.map((check) => {
                                const on = picked.includes(check.uuid);

                                return (
                                    <button
                                        key={check.uuid}
                                        type="button"
                                        aria-pressed={on}
                                        title={check.project ?? undefined}
                                        className={cn(chip(on), 'font-mono')}
                                        onClick={() =>
                                            setOption(
                                                field.key,
                                                on
                                                    ? picked.filter(
                                                          (u) =>
                                                              u !== check.uuid,
                                                      )
                                                    : [...picked, check.uuid],
                                            )
                                        }
                                    >
                                        {check.name}
                                    </button>
                                );
                            })}
                            {catalog.checks.length === 0 && (
                                <span className="text-xs text-ink-3">
                                    {t('screens.inspector.no_checks')}
                                </span>
                            )}
                        </div>
                        <span className="text-xs text-ink-3">
                            {t('screens.inspector.checks_hint')}
                        </span>
                    </div>
                );
            }
            case 'projects': {
                const value =
                    raw === undefined
                        ? ''
                        : (raw as string[]).length === 0
                          ? ALL
                          : (raw as string[])[0];

                return (
                    <div
                        key={field.key}
                        className={cn('flex flex-col gap-1', span)}
                    >
                        <Label
                            htmlFor={id(field.key)}
                            className="text-xs text-ink-2"
                        >
                            {fieldLabel(field.key)}
                        </Label>
                        <Select
                            id={id(field.key)}
                            value={value}
                            onChange={(e) =>
                                setScope(
                                    'projects',
                                    e.target.value === ''
                                        ? null
                                        : e.target.value === ALL
                                          ? []
                                          : [e.target.value],
                                )
                            }
                        >
                            <option value="">
                                {t('screens.inspector.screen_default')}
                            </option>
                            <option value={ALL}>
                                {t('screens.inspector.all_projects')}
                            </option>
                            {catalog.projects.map((p) => (
                                <option key={p.uuid} value={p.uuid}>
                                    {p.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                );
            }
            case 'environment':
                return (
                    <div
                        key={field.key}
                        className={cn('flex flex-col gap-1', span)}
                    >
                        <Label
                            htmlFor={id(field.key)}
                            className="text-xs text-ink-2"
                        >
                            {fieldLabel(field.key)}
                        </Label>
                        <Select
                            id={id(field.key)}
                            value={String(raw ?? '')}
                            onChange={(e) =>
                                setScope('environment', e.target.value)
                            }
                        >
                            <option value="">
                                {t('screens.inspector.screen_default')}
                            </option>
                            <option value={ALL}>
                                {t('screens.inspector.all_environments')}
                            </option>
                            {catalog.environments.map((env) => (
                                <option key={env} value={env}>
                                    {env}
                                </option>
                            ))}
                        </Select>
                    </div>
                );
            case 'timezone':
                return (
                    <div
                        key={field.key}
                        className={cn('flex flex-col gap-1', span)}
                    >
                        <Label
                            htmlFor={id(field.key)}
                            className="text-xs text-ink-2"
                        >
                            {fieldLabel(field.key)}
                        </Label>
                        <Select
                            id={id(field.key)}
                            value={String(raw ?? '')}
                            onChange={(e) =>
                                setOption(field.key, e.target.value)
                            }
                        >
                            <option value="">
                                {t('screens.inspector.screen_timezone')}
                            </option>
                            {timezones().map((zone) => (
                                <option key={zone} value={zone}>
                                    {zone}
                                </option>
                            ))}
                        </Select>
                    </div>
                );
            default:
                return (
                    <div
                        key={field.key}
                        className={cn('flex flex-col gap-1', span)}
                    >
                        <Label
                            htmlFor={id(field.key)}
                            className="text-xs text-ink-2"
                        >
                            {fieldLabel(field.key)}
                        </Label>
                        <Input
                            id={id(field.key)}
                            value={String(raw ?? '')}
                            placeholder={t(
                                `screens.fields.${field.key}.placeholder`,
                            )}
                            onChange={(e) =>
                                scope
                                    ? setScope(
                                          field.key as keyof WidgetScope,
                                          e.target.value,
                                      )
                                    : setOption(field.key, e.target.value)
                            }
                        />
                    </div>
                );
        }
    };

    const sizes: [number, number][] = [
        [1, 1],
        [2, 1],
        [2, 2],
        [Math.min(4, cols), 2],
    ];
    const lowIsBad =
        (widget.options.low_is_bad as boolean | undefined) ??
        source?.low_is_bad;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <h2 className="text-[11px] font-semibold tracking-wide text-ink-3 uppercase">
                    {t(`screens.kinds.${widget.type}`)}
                </h2>
                <Button variant="ghost" className="text-err" onClick={onRemove}>
                    {t('screens.inspector.remove')}
                </Button>
            </div>

            {widget.type === 'empty' && (
                <p className="text-[13px] text-ink-2">
                    {t('screens.inspector.empty_hint')}
                </p>
            )}

            {widget.type !== 'empty' && (
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor={id('title')}>
                        {t('screens.inspector.title')}
                    </Label>
                    <Input
                        id={id('title')}
                        value={widget.title ?? ''}
                        placeholder={
                            source?.label ?? t(`screens.kinds.${widget.type}`)
                        }
                        onChange={(e) =>
                            onChange({
                                ...widget,
                                title: e.target.value || null,
                            })
                        }
                    />
                </div>
            )}

            {pool.length > 1 && (
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor={id('source')}>
                        {t('screens.inspector.shows')}
                    </Label>
                    <Select
                        id={id('source')}
                        value={widget.source ?? ''}
                        onChange={(e) => pickSource(e.target.value)}
                    >
                        {pool.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.label}
                            </option>
                        ))}
                    </Select>
                </div>
            )}

            {ROTATING_KINDS.includes(widget.type) && (
                <div className="flex flex-col gap-1.5">
                    <span className="text-[13px] font-medium">
                        {t('screens.inspector.rotate')}
                    </span>
                    <div className="flex flex-wrap gap-1.5">
                        {pool
                            .filter((s) => s.id !== widget.source)
                            .map((s) => {
                                const on = widget.rotate.includes(s.id);

                                return (
                                    <button
                                        key={s.id}
                                        type="button"
                                        aria-pressed={on}
                                        className={chip(on)}
                                        onClick={() =>
                                            onChange({
                                                ...widget,
                                                rotate: on
                                                    ? widget.rotate.filter(
                                                          (r) => r !== s.id,
                                                      )
                                                    : [...widget.rotate, s.id],
                                            })
                                        }
                                    >
                                        {s.label}
                                    </button>
                                );
                            })}
                    </div>
                    <span className="text-xs text-ink-3">
                        {widget.rotate.length > 0
                            ? t('screens.inspector.rotate_on', {
                                  count: widget.rotate.length + 1,
                              })
                            : t('screens.inspector.rotate_off')}
                    </span>
                </div>
            )}

            {(widget.type === 'stat' || widget.type === 'gauge') && (
                <p className="text-xs text-ink-3">
                    {t(
                        lowIsBad
                            ? 'screens.inspector.limits_below'
                            : 'screens.inspector.limits_above',
                    )}
                    {source?.unit === 'ratio' &&
                        ' ' + t('screens.inspector.ratio_hint')}
                    {source?.unit === 'ms' &&
                        ' ' + t('screens.inspector.ms_hint')}
                </p>
            )}

            {(schema[widget.type] ?? []).map((section, i) => (
                <Section
                    key={section.id}
                    title={t(`screens.sections.${section.id}`)}
                    open={i === 0}
                >
                    {section.fields.map((field) =>
                        renderField(field, section.id === 'scope'),
                    )}
                </Section>
            ))}

            <div className="flex flex-col gap-1.5">
                <span className="text-[13px] font-medium">
                    {t('screens.inspector.size')}
                </span>
                <div
                    role="group"
                    aria-label={t('screens.inspector.size')}
                    className="grid grid-cols-4 gap-1.5"
                >
                    {sizes.map(([w, h]) => {
                        const on = widget.w === w && widget.h === h;

                        return (
                            <button
                                key={`${w}x${h}`}
                                type="button"
                                aria-pressed={on}
                                className={cn(
                                    'h-8 rounded-md border border-line text-[13px]',
                                    on
                                        ? 'bg-amber text-amber-ink'
                                        : 'bg-rail text-ink-2',
                                )}
                                onClick={() => onChange({ ...widget, w, h })}
                            >
                                {`${w} × ${h}`}
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

/** Settings of the whole screen, shown while no widget is selected. */
export function ScreenSettings({
    screen,
    catalog,
    onChange,
}: {
    screen: Screen;
    catalog: Catalog;
    onChange: (changes: Partial<Screen>) => void;
}) {
    const t = useT();
    const scope = screen.scope;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="text-[15px] font-semibold">
                    {t('screens.settings.title')}
                </h2>
                <p className="text-[13px] text-ink-2">
                    {t('screens.settings.hint')}
                </p>
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="screen-name">
                    {t('screens.settings.name')}
                </Label>
                <Input
                    id="screen-name"
                    value={screen.name}
                    maxLength={100}
                    onChange={(e) => onChange({ name: e.target.value })}
                />
            </div>
            <fieldset className="flex flex-col gap-1.5">
                <legend className="mb-1.5 text-[13px] font-medium">
                    {t('screens.settings.projects')}
                </legend>
                {catalog.projects.map((p) => (
                    <label
                        key={p.uuid}
                        className="flex items-center gap-2 text-[13px] text-ink-2"
                    >
                        <input
                            type="checkbox"
                            checked={scope.projects.includes(p.uuid)}
                            onChange={(e) =>
                                onChange({
                                    scope: {
                                        ...scope,
                                        projects: e.target.checked
                                            ? [...scope.projects, p.uuid]
                                            : scope.projects.filter(
                                                  (u) => u !== p.uuid,
                                              ),
                                    },
                                })
                            }
                        />
                        {p.name}
                    </label>
                ))}
                <span className="text-xs text-ink-3">
                    {t('screens.settings.projects_hint')}
                </span>
            </fieldset>
            <div className="grid grid-cols-2 gap-2.5">
                <div className="flex flex-col gap-1">
                    <Label htmlFor="screen-env" className="text-xs text-ink-2">
                        {t('screens.fields.environment.label')}
                    </Label>
                    <Select
                        id="screen-env"
                        value={scope.environment ?? ''}
                        onChange={(e) =>
                            onChange({
                                scope: {
                                    ...scope,
                                    environment: e.target.value || null,
                                },
                            })
                        }
                    >
                        <option value="">
                            {t('screens.inspector.all_environments')}
                        </option>
                        {catalog.environments.map((env) => (
                            <option key={env} value={env}>
                                {env}
                            </option>
                        ))}
                    </Select>
                </div>
                <div className="flex flex-col gap-1">
                    <Label
                        htmlFor="screen-period"
                        className="text-xs text-ink-2"
                    >
                        {t('screens.fields.period.label')}
                    </Label>
                    <Select
                        id="screen-period"
                        value={scope.period}
                        onChange={(e) =>
                            onChange({
                                scope: { ...scope, period: e.target.value },
                            })
                        }
                    >
                        {catalog.periods.map((p) => (
                            <option key={p} value={p}>
                                {t(`screens.periods.${p}`)}
                            </option>
                        ))}
                    </Select>
                </div>
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="screen-tz">
                    {t('screens.settings.timezone')}
                </Label>
                <Select
                    id="screen-tz"
                    value={screen.timezone}
                    onChange={(e) => onChange({ timezone: e.target.value })}
                >
                    {timezones(screen.timezone).map((zone) => (
                        <option key={zone} value={zone}>
                            {zone}
                        </option>
                    ))}
                </Select>
                <span className="text-xs text-ink-3">
                    {t('screens.settings.timezone_hint')}
                </span>
            </div>
        </div>
    );
}
