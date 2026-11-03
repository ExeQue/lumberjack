import type { ReactNode } from 'react';
import { Button, Input, Label, Select } from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { itemFieldsOf, option, rotationOf } from './lib';
import type {
    RotateItem,
    Schema,
    SchemaField,
    Screen,
    Source,
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

/**
 * Where a field reads and writes: the widget's own options, or a rotated value's, which
 * inherit the widget's (shown as "Same as widget").
 */
type Target = {
    values: Record<string, unknown>;
    set: (key: string, value: unknown) => void;
    source: Source | undefined;
    inherited?: Record<string, unknown>;
    prefix: string;
};

/** The selected widget's settings, drawn from its type's option schema. */
export function WidgetInspector({
    widget,
    catalog,
    onChange,
    onRemove,
    cols,
    canResize,
}: {
    widget: Widget;
    catalog: Catalog;
    onChange: (widget: Widget) => void;
    onRemove: () => void;
    cols: number;
    /** Whether the widget has room for that size where it is. */
    canResize: (w: number, h: number) => boolean;
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

    const rotated = rotationOf(widget).slice(1);
    const setRotate = (items: RotateItem[]) =>
        onChange({
            ...widget,
            // A value without settings of its own stays a plain id, as the server stores it.
            rotate: items.map((item) =>
                Object.keys(item).length === 1 ? item.source : item,
            ),
        });
    const setItem = (index: number, changes: Partial<RotateItem>) => {
        const item: Record<string, unknown> = {
            ...rotated[index],
            ...changes,
        };

        for (const key of Object.keys(item)) {
            const v = item[key];

            if (
                v === undefined ||
                v === null ||
                v === '' ||
                (typeof v === 'object' && Object.keys(v).length === 0)
            ) {
                delete item[key];
            }
        }

        setRotate(
            rotated.map((r, i) => (i === index ? (item as RotateItem) : r)),
        );
    };
    const moveItem = (index: number, by: number) => {
        const items = [...rotated];
        const [item] = items.splice(index, 1);
        items.splice(index + by, 0, item);
        setRotate(items);
    };
    const without = (
        values: Record<string, unknown> | undefined,
        key: string,
        value: unknown,
    ) => {
        const next = { ...values };

        if (value === '' || value === null || value === undefined) {
            delete next[key];
        } else {
            next[key] = value;
        }

        return next;
    };

    const pickSource = (next: string) =>
        onChange({
            ...widget,
            source: next,
            title: null,
            rotate: rotated.filter((r) => r.source !== next),
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
    const same = (value: string) =>
        t('screens.inspector.same_as_widget', { value });
    const every = Number(option<string>(schema, widget, 'rotate_every') ?? 10);
    const hasPeriod = (schema[widget.type] ?? []).some((section) =>
        section.fields.some((f) => f.key === 'period'),
    );
    const widgetPeriod = widget.scope.period
        ? t(`screens.period_short.${widget.scope.period}`)
        : t('screens.inspector.period_screen');

    const own: Target = {
        values: widget.options,
        set: setOption,
        source,
        prefix: '',
    };

    const renderField = (
        field: SchemaField,
        scope: boolean,
        target: Target = own,
    ): ReactNode => {
        const span = field.span === 2 ? 'col-span-2' : '';
        const id = (key: string) => `w-${widget.id}-${target.prefix}${key}`;
        const inherited = target.inherited;
        const raw = (
            scope
                ? (widget.scope as Record<string, unknown>)[field.key]
                : target.values[field.key]
        ) as string | number | string[] | undefined;
        // A rotated value's toggle can also follow the widget's.
        if (inherited && field.type === 'toggle') {
            const from =
                field.key === 'low_is_bad'
                    ? ((inherited.low_is_bad as boolean | undefined) ??
                      target.source?.low_is_bad ??
                      false)
                    : option<boolean>(schema, widget, field.key) === true;

            return (
                <div key={field.key} className="flex flex-col gap-1">
                    <Label
                        htmlFor={id(field.key)}
                        className="text-xs text-ink-2"
                    >
                        {fieldLabel(field.key)}
                    </Label>
                    <Select
                        id={id(field.key)}
                        value={raw === undefined ? '' : String(raw)}
                        onChange={(e) =>
                            target.set(
                                field.key,
                                e.target.value === ''
                                    ? null
                                    : e.target.value === 'true',
                            )
                        }
                    >
                        <option value="">
                            {same(
                                t(
                                    from
                                        ? 'screens.inspector.on'
                                        : 'screens.inspector.off',
                                ),
                            )}
                        </option>
                        <option value="true">
                            {t('screens.inspector.on')}
                        </option>
                        <option value="false">
                            {t('screens.inspector.off')}
                        </option>
                    </Select>
                </div>
            );
        }

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
                                raw ??
                                    (scope || inherited
                                        ? ''
                                        : (field.default ?? '')),
                            )}
                            onChange={(e) =>
                                scope
                                    ? setScope(
                                          field.key as keyof WidgetScope,
                                          e.target.value,
                                      )
                                    : target.set(
                                          field.key,
                                          e.target.value === '' ||
                                              (!inherited &&
                                                  e.target.value ===
                                                      field.default)
                                              ? null
                                              : e.target.value,
                                      )
                            }
                        >
                            {inherited && (
                                <option value="">
                                    {same(
                                        t(
                                            `screens.fields.${field.key}.options.${option<string>(schema, widget, field.key)}`,
                                        ),
                                    )}
                                </option>
                            )}
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
                const limit =
                    field.key === 'amber' || field.key === 'red'
                        ? target.source?.[field.key]
                        : null;
                const fallback = inherited
                    ? ((inherited[field.key] as number | undefined) ?? limit)
                    : limit;

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
                                target.set(
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
                            placeholder={
                                inherited &&
                                typeof inherited[field.key] === 'string'
                                    ? String(inherited[field.key])
                                    : t(
                                          `screens.fields.${field.key}.placeholder`,
                                      )
                            }
                            onChange={(e) =>
                                scope
                                    ? setScope(
                                          field.key as keyof WidgetScope,
                                          e.target.value,
                                      )
                                    : target.set(field.key, e.target.value)
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

            {hasPeriod && (
                <div className="flex flex-col gap-1.5">
                    <span className="text-[13px] font-medium">
                        {t('screens.inspector.period')}
                    </span>
                    <div
                        role="radiogroup"
                        aria-label={t('screens.inspector.period')}
                        className="flex flex-wrap gap-1.5"
                    >
                        {['', ...catalog.periods].map((p) => {
                            const on = (widget.scope.period ?? '') === p;

                            return (
                                <button
                                    key={p || 'screen'}
                                    type="button"
                                    role="radio"
                                    aria-checked={on}
                                    className={chip(on)}
                                    onClick={() => setScope('period', p)}
                                >
                                    {p === ''
                                        ? t('screens.inspector.period_screen')
                                        : t(`screens.period_short.${p}`)}
                                </button>
                            );
                        })}
                    </div>
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
                                const on = rotated.some(
                                    (r) => r.source === s.id,
                                );

                                return (
                                    <button
                                        key={s.id}
                                        type="button"
                                        aria-pressed={on}
                                        className={chip(on)}
                                        onClick={() =>
                                            setRotate(
                                                on
                                                    ? rotated.filter(
                                                          (r) =>
                                                              r.source !== s.id,
                                                      )
                                                    : [
                                                          ...rotated,
                                                          { source: s.id },
                                                      ],
                                            )
                                        }
                                    >
                                        {s.label}
                                    </button>
                                );
                            })}
                    </div>
                    <span className="text-xs text-ink-3">
                        {rotated.length > 0
                            ? t('screens.inspector.rotate_on', {
                                  count: rotated.length + 1,
                              })
                            : t('screens.inspector.rotate_off')}
                    </span>
                    {rotated.length > 0 && (
                        <ol className="flex flex-col gap-1">
                            <li className="flex items-center gap-2 rounded-md border border-line px-2 py-1.5 text-[13px]">
                                <span className="min-w-0 flex-1 truncate">
                                    {source?.label}
                                    <span className="ml-1.5 text-xs text-ink-3">
                                        {t('screens.inspector.rotate_first')}
                                    </span>
                                </span>
                                <Input
                                    aria-label={t(
                                        'screens.fields.source_seconds.label',
                                    )}
                                    type="number"
                                    min={1}
                                    className="h-7 w-20"
                                    value={String(
                                        (widget.options.source_seconds as
                                            | number
                                            | undefined) ?? '',
                                    )}
                                    placeholder={`${every} s`}
                                    onChange={(e) =>
                                        setOption(
                                            'source_seconds',
                                            e.target.value === ''
                                                ? null
                                                : Number(e.target.value),
                                        )
                                    }
                                />
                            </li>
                            {rotated.map((item, i) => {
                                const itemSource = pool.find(
                                    (s) => s.id === item.source,
                                );
                                const target: Target = {
                                    values: item.options ?? {},
                                    set: (key, value) =>
                                        setItem(i, {
                                            options: without(
                                                item.options,
                                                key,
                                                value,
                                            ),
                                        }),
                                    source: itemSource,
                                    inherited: widget.options,
                                    prefix: `r${i}-`,
                                };
                                const rid = (key: string) =>
                                    `w-${widget.id}-r${i}-${key}`;

                                return (
                                    <li
                                        key={item.source}
                                        className="rounded-md border border-line text-[13px]"
                                    >
                                        <details>
                                            <summary className="flex cursor-pointer items-center gap-1.5 px-2 py-1.5">
                                                <span className="min-w-0 flex-1 truncate">
                                                    {item.title ??
                                                        itemSource?.label ??
                                                        item.source}
                                                </span>
                                                <span className="text-xs text-ink-3">
                                                    {`${item.seconds ?? every} s`}
                                                </span>
                                                {[-1, 1].map((by) => (
                                                    <button
                                                        key={by}
                                                        type="button"
                                                        disabled={
                                                            i + by < 0 ||
                                                            i + by >=
                                                                rotated.length
                                                        }
                                                        aria-label={t(
                                                            by < 0
                                                                ? 'screens.inspector.move_up'
                                                                : 'screens.inspector.move_down',
                                                        )}
                                                        className="rounded px-1 text-ink-3 hover:text-ink disabled:opacity-30"
                                                        onClick={(e) => {
                                                            e.preventDefault();
                                                            moveItem(i, by);
                                                        }}
                                                    >
                                                        {by < 0 ? '↑' : '↓'}
                                                    </button>
                                                ))}
                                            </summary>
                                            <div className="grid grid-cols-2 gap-2.5 border-t border-line p-2">
                                                <div className="flex flex-col gap-1">
                                                    <Label
                                                        htmlFor={rid('seconds')}
                                                        className="text-xs text-ink-2"
                                                    >
                                                        {t(
                                                            'screens.inspector.rotate_seconds',
                                                        )}
                                                    </Label>
                                                    <Input
                                                        id={rid('seconds')}
                                                        type="number"
                                                        min={1}
                                                        value={String(
                                                            item.seconds ?? '',
                                                        )}
                                                        placeholder={`${every} s`}
                                                        onChange={(e) =>
                                                            setItem(i, {
                                                                seconds:
                                                                    e.target
                                                                        .value ===
                                                                    ''
                                                                        ? undefined
                                                                        : Number(
                                                                              e
                                                                                  .target
                                                                                  .value,
                                                                          ),
                                                            })
                                                        }
                                                    />
                                                </div>
                                                <div className="flex flex-col gap-1">
                                                    <Label
                                                        htmlFor={rid('title')}
                                                        className="text-xs text-ink-2"
                                                    >
                                                        {t(
                                                            'screens.inspector.title',
                                                        )}
                                                    </Label>
                                                    <Input
                                                        id={rid('title')}
                                                        value={item.title ?? ''}
                                                        placeholder={
                                                            itemSource?.label
                                                        }
                                                        onChange={(e) =>
                                                            setItem(i, {
                                                                title:
                                                                    e.target
                                                                        .value ||
                                                                    undefined,
                                                            })
                                                        }
                                                    />
                                                </div>
                                                {hasPeriod && (
                                                    <div
                                                        role="radiogroup"
                                                        aria-label={t(
                                                            'screens.inspector.period',
                                                        )}
                                                        className="col-span-2 flex flex-wrap gap-1.5"
                                                    >
                                                        {[
                                                            '',
                                                            ...catalog.periods,
                                                        ].map((p) => {
                                                            const on =
                                                                (item.scope
                                                                    ?.period ??
                                                                    '') === p;

                                                            return (
                                                                <button
                                                                    key={
                                                                        p ||
                                                                        'widget'
                                                                    }
                                                                    type="button"
                                                                    role="radio"
                                                                    aria-checked={
                                                                        on
                                                                    }
                                                                    className={chip(
                                                                        on,
                                                                    )}
                                                                    onClick={() =>
                                                                        setItem(
                                                                            i,
                                                                            {
                                                                                scope: p
                                                                                    ? {
                                                                                          period: p,
                                                                                      }
                                                                                    : undefined,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    {p === ''
                                                                        ? same(
                                                                              widgetPeriod,
                                                                          )
                                                                        : t(
                                                                              `screens.period_short.${p}`,
                                                                          )}
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                )}
                                                {itemFieldsOf(
                                                    schema,
                                                    widget.type,
                                                ).map((field) =>
                                                    renderField(
                                                        field,
                                                        false,
                                                        target,
                                                    ),
                                                )}
                                            </div>
                                        </details>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
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
                    {section.fields
                        // Set on the source's row under Rotate through.
                        .filter((field) => field.key !== 'source_seconds')
                        .map((field) =>
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
                                disabled={!on && !canResize(w, h)}
                                className={cn(
                                    'h-8 rounded-md border border-line text-[13px] disabled:opacity-40',
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
