/** The shapes of connected source each widget can show. */
const CONNECTED_SHAPES: Record<string, string[]> = {
    stat: ['number'],
    gauge: ['number'],
    chart: ['series'],
    list: ['list', 'table'],
};

import { TreeSelect } from '@/components/lj/tree-select';
import { Plus, Settings, X } from 'lucide-react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import type { ReactNode } from 'react';
import {
    Button,
    Input,
    inputClass,
    Label,
    Select,
    Switch,
} from '@/components/lj/ui';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { PATH_FIELDS, PathConfig, useJsonPreview } from './json-path-help';
import { fieldsOf, option, rotationOf } from './lib';
import { TimezoneSelect } from './timezone-select';
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
import { MAX_SLIDES, MAX_ZONES, ROTATING_KINDS } from './types';

export type Catalog = {
    schema: Schema;
    sources: Sources;
    /** The projects a widget may pick: the screen's, or the team's when it names none. */
    projects: { uuid: string; name: string }[];
    /** Every project of the team, for the screen's own settings. */
    teamProjects: { uuid: string; name: string }[];
    /** Whether the screen names projects, which then bound every widget. */
    scoped: boolean;
    environments: string[];
    checks: {
        uuid: string;
        name: string;
        project: string | null;
        project_uuid?: string | null;
    }[];
    /** The team's data sources, which External JSON values and calendars pick from. */
    data_sources?: {
        uuid: string;
        name: string;
        format: 'json' | 'prometheus' | 'ical' | 'connected';
        /** A connected source's shape, which decides the widgets that can show it. */
        shape?: 'number' | 'series' | 'list' | 'table' | null;
        environment?: string | null;
        project: string | null;
        project_uuid?: string | null;
        /** The group its app files it under, such as "Events > Storage". */
        group?: string | null;
        /** The connected app it comes from, which roots it in the tree. */
        host: string | null;
        /** The periods a connected service can answer for; null for all. */
        periods?: string[] | null;
    }[];
    /** The screen's uuid, for the External JSON preview; without it there is none. */
    screen?: string;
    periods: string[];
    /** Known names per filter kind (queue, host, domain), offered while typing a filter. */
    filters?: Record<string, string[]>;
    /** What some filter names are shown as, by name. */
    filterLabels?: Record<string, string>;
};

const ALL = '*';

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
    /** Several at once, as one change (set after set would each start from the old values). */
    setMany: (changes: Record<string, unknown>) => void;
    source: Source | undefined;
    inherited?: Record<string, unknown>;
    prefix: string;
};

/** Options that belong to one source: a new source starts from its own limits and scale. */
const SOURCE_BOUND = ['amber', 'red', 'low_is_bad', 'min', 'max'];

// ponytail: mirrors WidgetSchema::MAX_ROTATE; the server trims past it anyway.
const MAX_ROTATE = 12;

/** A rotated value as stored: its empty settings dropped, and a plain id when none are left. */
function compact(item: RotateItem): string | RotateItem {
    const out: Record<string, unknown> = { ...item };

    for (const key of Object.keys(out)) {
        const v = out[key];

        if (
            v === undefined ||
            v === null ||
            v === '' ||
            (typeof v === 'object' && Object.keys(v).length === 0)
        ) {
            delete out[key];
        }
    }

    return Object.keys(out).length === 1 ? item.source : (out as RotateItem);
}

/** One value as the drag of a tab carries it, apart from the designer's own drags. */
const TAB_DRAG = 'application/x-lj-rotate-value';

/**
 * The selected widget's settings, drawn from its type's option schema. A rotating type
 * shows one tab per value it shows in turn, the widget's own source first; below them
 * what applies to the whole widget.
 */
export function WidgetInspector({
    widget,
    catalog,
    onChange,
    onRemove,
    tab = 0,
    onTab = () => {},
    part = 'all',
}: {
    widget: Widget;
    catalog: Catalog;
    onChange: (widget: Widget) => void;
    onRemove: () => void;
    /** The value of the rotation open in the tabs, which the designer's tile shows. */
    tab?: number;
    onTab?: (index: number) => void;
    /** Only the Display fields, for the gear on the designer's tile (DisplayGear). */
    part?: 'all' | 'display';
}) {
    const t = useT();
    const [checksProject, setChecksProject] = useState<string | null>(null);
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

    const rotating = ROTATING_KINDS.includes(widget.type);
    // Health's values all show its one source, told apart by their checks.
    const repeats = widget.type === 'health';
    const items = rotating ? rotationOf(widget) : [];
    const rotated = items.slice(1);
    const active = Math.min(tab, Math.max(0, items.length - 1));
    const first = active === 0;
    const item = items[active];
    const tablist = useRef<HTMLDivElement>(null);

    // The open tab scrolls fully into view, however it was opened.
    useEffect(() => {
        tablist.current
            ?.querySelectorAll<HTMLElement>('[role="tab"]')
            [active]?.scrollIntoView({ inline: 'nearest', block: 'nearest' });
    }, [active]);
    // What the active value's filter narrows: a queue, host, job or route.
    const filterOn =
        pool.find((s) => s.id === (item?.source ?? widget.source))?.filter_on ??
        null;

    const setRotate = (entries: RotateItem[]) =>
        onChange({ ...widget, rotate: entries.map(compact) });
    const setItem = (index: number, changes: Partial<RotateItem>) =>
        setRotate(
            rotated.map((r, i) => (i === index ? { ...r, ...changes } : r)),
        );
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

    /**
     * The widget with its values in this order. A value that becomes the first carries
     * its settings into the widget's; every other value keeps what it showed by taking
     * the widget's old setting as its own wherever the new first value overrode it.
     */
    const arrange = (order: RotateItem[]) => {
        const [lead, ...rest] = order;

        if (lead === items[0]) {
            setRotate(rest);

            return;
        }

        const fields = fieldsOf(schema, widget.type);
        const options: Record<string, unknown> = {
            ...widget.options,
            ...lead.options,
        };
        delete options.source_seconds;

        // A health value's checks are its own, never the widget's: the old first keeps
        // its checks, the new first brings its own (none for all checks).
        if (repeats) {
            delete options.checks;

            if (lead.options?.checks) {
                options.checks = lead.options.checks;
            }
        }

        if (lead.seconds) {
            options.source_seconds = lead.seconds;
        }

        // What a value showed for a key it did not set itself, before the reorder.
        const before = (r: RotateItem, key: string) =>
            widget.options[key] ??
            (['amber', 'red', 'low_is_bad'].includes(key)
                ? pool.find((s) => s.id === r.source)?.[
                      key as 'amber' | 'red' | 'low_is_bad'
                  ]
                : fields[key]?.default) ??
            undefined;

        onChange({
            ...widget,
            source: lead.source,
            title: lead.title ?? null,
            options,
            scope: { ...widget.scope, ...lead.scope },
            rotate: rest
                .map((r) => ({
                    ...r,
                    // ponytail: "the screen's default", an automatic scale and a source
                    // without a default limit have no stored form, so a value that
                    // followed them follows the new first one instead.
                    scope: {
                        ...Object.fromEntries(
                            Object.keys(lead.scope ?? {})
                                .map((key) => [
                                    key,
                                    widget.scope[key as keyof WidgetScope],
                                ])
                                .filter(([, v]) => v !== undefined),
                        ),
                        ...r.scope,
                    },
                    options: {
                        ...Object.fromEntries(
                            Object.keys(lead.options ?? {})
                                .filter((key) => !repeats || key !== 'checks')
                                .map((key) => [key, before(r, key)])
                                .filter(([, v]) => v !== undefined),
                        ),
                        ...(repeats && r === items[0]
                            ? { checks: widget.options.checks }
                            : {}),
                        ...r.options,
                    },
                }))
                .map(compact),
        });
    };
    const move = (from: number, to: number) => {
        const order = [...items];
        const [moved] = order.splice(from, 1);
        order.splice(to, 0, moved);
        arrange(order);
        onTab(to);
    };
    const removeValue = (index: number) => {
        arrange(items.filter((_, i) => i !== index));
        onTab(Math.min(index, items.length - 2));
    };
    const unused = pool.find((s) => !items.some((i) => i.source === s.id));
    // The checks a value shows; on the first tab the widget's own.
    const checksOf = (r: RotateItem, i: number) =>
        ((i === 0 ? widget.options.checks : r.options?.checks) as
            | string[]
            | undefined) ?? [];
    // The next check not on a tab yet, else none picked (all checks).
    const nextCheck = catalog.checks.find(
        (c) => !items.some((r, i) => checksOf(r, i).includes(c.uuid)),
    );
    const canAdd = (repeats || unused) && rotated.length < MAX_ROTATE;
    const addValue = () => {
        if (repeats && widget.source) {
            setRotate([
                ...rotated,
                {
                    source: widget.source,
                    options: nextCheck
                        ? { checks: [nextCheck.uuid] }
                        : undefined,
                },
            ]);
            onTab(items.length);
        } else if (unused) {
            setRotate([...rotated, { source: unused.id }]);
            onTab(items.length);
        }
    };

    const pickSource = (next: string) =>
        first
            ? onChange({
                  ...widget,
                  source: next,
                  title: null,
                  rotate: rotated.filter((r) => r.source !== next),
                  options: Object.fromEntries(
                      Object.entries(widget.options).filter(
                          ([key]) => !SOURCE_BOUND.includes(key),
                      ),
                  ),
              })
            : setItem(active - 1, {
                  source: next,
                  title: undefined,
                  options: Object.fromEntries(
                      Object.entries(item.options ?? {}).filter(
                          ([key]) => !SOURCE_BOUND.includes(key),
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
        setMany: (changes) =>
            onChange({
                ...widget,
                options: Object.entries(changes).reduce(
                    (options, [key, value]) => without(options, key, value),
                    widget.options,
                ),
            }),
        source,
        prefix: '',
    };

    const renderField = (
        field: SchemaField,
        scope: boolean,
        target: Target = own,
    ): ReactNode => {
        // A field for another choice (the QR code's wifi fields while it holds a link).
        if (
            field.when &&
            !Object.entries(field.when).every(([key, values]) =>
                values.includes(
                    String(
                        target.values[key] ??
                            target.inherited?.[key] ??
                            option(catalog.schema, widget, key),
                    ),
                ),
            )
        ) {
            return null;
        }

        const span = field.span === 2 ? 'col-span-2' : '';
        const id = (key: string) => `w-${widget.id}-${target.prefix}${key}`;
        const inherited = target.inherited;
        const raw = (
            scope
                ? // Own keys only: an empty scope that arrived as [] has a filter() method.
                  Object.hasOwn(scopeTarget.values ?? {}, field.key)
                    ? (scopeTarget.values as Record<string, unknown>)[field.key]
                    : undefined
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

        // An External JSON value's paths are set in a dialog, summed up here.
        // A connected source's answer has no history to compare with or to draw.
        if (connected && ['compare', 'sparkline'].includes(field.key)) {
            return null;
        }

        // Only an app can answer at another resolution; a fetched source has its history.
        if (!connected && field.key === 'resolution') {
            return null;
        }

        if (PATH_FIELDS.includes(field.key)) {
            if (connected) {
                return null;
            }

            return (
                field.key === 'json_path' && (
                    <PathConfig
                        key={`${target.prefix}${field.key}`}
                        screen={part === 'all' ? catalog.screen : undefined}
                        source={effective('data_source') || undefined}
                        type={widget.type}
                        prometheus={prometheus}
                        fields={PATH_FIELDS.filter(
                            (key) =>
                                key === 'json_path' ||
                                (!prometheus &&
                                    (schema[widget.type] ?? []).some((s) =>
                                        s.fields.some((f) => f.key === key),
                                    )),
                        )}
                        values={target.values}
                        inherited={target.inherited}
                        preview={preview}
                        onApply={target.setMany}
                    />
                )
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
                                    ? scopeTarget.set(
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
                            {scope && scopeTarget.inherited && (
                                <option value="">
                                    {same(
                                        t(
                                            `screens.fields.${field.key}.options.${scopeTarget.inherited[field.key as 'release'] ?? field.default}`,
                                        ),
                                    )}
                                </option>
                            )}
                            {scope && field.key === 'period' && (
                                <option value="">
                                    {t('screens.inspector.screen_default')}
                                </option>
                            )}
                            {Object.entries(field.groups ?? {}).map(
                                ([group, values]) => (
                                    <optgroup
                                        key={group}
                                        label={t(
                                            `screens.fields.${field.key}.groups.${group}`,
                                        )}
                                    >
                                        {values.map((value) => (
                                            <option key={value} value={value}>
                                                {t(
                                                    `screens.fields.${field.key}.options.${value}`,
                                                )}
                                            </option>
                                        ))}
                                    </optgroup>
                                ),
                            )}
                            {(field.groups ? [] : (field.options ?? [])).map(
                                (value) => (
                                    <option key={value} value={value}>
                                        {field.key === 'period'
                                            ? t(`screens.periods.${value}`)
                                            : t(
                                                  `screens.fields.${field.key}.options.${value}`,
                                              )}
                                    </option>
                                ),
                            )}
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
                        className={cn(
                            'flex items-center gap-2 text-[13px] text-ink-2',
                            // Half width: on the line of the select beside it.
                            span || 'self-end pb-2',
                        )}
                    >
                        <Switch
                            small
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
                            label={fieldLabel(field.key)}
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
            case 'data_source': {
                // A calendar reads an iCalendar source, External JSON any other.
                const calendar =
                    target.source?.id === 'calendar' ||
                    widget.type === 'countdown';
                const all = (catalog.data_sources ?? []).filter(
                    (s) =>
                        (s.format === 'ical') === calendar &&
                        (s.format !== 'connected' ||
                            CONNECTED_SHAPES[widget.type]?.includes(
                                s.shape ?? '',
                            )),
                );
                const oneEnvironment =
                    new Set(all.map((s) => s.environment)).size === 1;

                return (
                    <div
                        key={field.key}
                        className="col-span-2 flex flex-col gap-1"
                    >
                        <Label
                            htmlFor={id(field.key)}
                            className="text-xs text-ink-2"
                        >
                            {fieldLabel(field.key)}
                        </Label>
                        <TreeSelect
                            id={id(field.key)}
                            label={fieldLabel(field.key)}
                            options={all.map((s) => ({
                                value: s.uuid,
                                keywords: s.project ?? '',
                                label:
                                    s.environment && !oneEnvironment
                                        ? `${s.name} (${s.environment})`
                                        : s.name,
                                path: [
                                    ...(s.host || s.project
                                        ? [s.host ?? s.project ?? '']
                                        : []),
                                    ...(s.group ? s.group.split(' > ') : []),
                                ],
                            }))}
                            value={String(raw ?? '')}
                            onChange={(uuid) => target.set(field.key, uuid)}
                            placeholder={t('screens.fields.data_source.none')}
                            searchPlaceholder={t(
                                'screens.fields.data_source.search',
                            )}
                            noMatches={t(
                                'screens.fields.data_source.no_matches',
                            )}
                            clearLabel={t('screens.fields.data_source.clear')}
                        />
                        {all.length === 0 && (
                            <span className="text-xs text-ink-3">
                                {t('screens.fields.data_source.empty')}
                            </span>
                        )}
                    </div>
                );
            }
            case 'checks': {
                const picked = (raw as string[] | undefined) ?? [];
                const groups = Object.entries(
                    Object.groupBy(catalog.checks, (c) => c.project ?? ''),
                ).sort(([a], [b]) => a.localeCompare(b));
                const pickedIn = (group: typeof catalog.checks = []) =>
                    group.filter((c) => picked.includes(c.uuid)).length;
                // The chosen project, else the one holding a picked check, else the first.
                const [shownProject, shown = []] =
                    groups.find(([p]) => p === checksProject) ??
                    groups.find(([, g]) => pickedIn(g) > 0) ??
                    groups[0] ??
                    [];

                return (
                    <div
                        key={field.key}
                        className="col-span-2 flex flex-col gap-1.5"
                    >
                        <span className="text-xs text-ink-2">
                            {fieldLabel(field.key)}
                        </span>
                        <div className="flex flex-col gap-2">
                            {groups.length > 1 && (
                                <div className="flex flex-wrap gap-1.5 border-b border-line pb-2">
                                    {groups.map(([project, group]) => (
                                        <button
                                            key={project}
                                            type="button"
                                            aria-pressed={
                                                project === shownProject
                                            }
                                            className={chip(
                                                project === shownProject,
                                            )}
                                            onClick={() =>
                                                setChecksProject(project)
                                            }
                                        >
                                            {project}
                                            {pickedIn(group) > 0 &&
                                                ` · ${pickedIn(group)}`}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <div className="flex flex-wrap gap-1.5">
                                {shown.map((check) => {
                                    const on = picked.includes(check.uuid);
                                    const next = on
                                        ? picked.filter((u) => u !== check.uuid)
                                        : [...picked, check.uuid];

                                    return (
                                        <button
                                            key={check.uuid}
                                            type="button"
                                            aria-pressed={on}
                                            className={cn(
                                                chip(on),
                                                'font-mono',
                                            )}
                                            onClick={() =>
                                                target.set(field.key, next)
                                            }
                                        >
                                            {check.name}
                                        </button>
                                    );
                                })}
                            </div>
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
                                scopeTarget.set(
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
                                {scopeTarget.inherited
                                    ? same(
                                          projectsLabel(
                                              scopeTarget.inherited.projects,
                                          ),
                                      )
                                    : t('screens.inspector.screen_default')}
                            </option>
                            <option value={ALL}>
                                {catalog.scoped
                                    ? t('screens.inspector.all_screen_projects')
                                    : t('screens.inspector.all_projects')}
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
                                scopeTarget.set('environment', e.target.value)
                            }
                        >
                            <option value="">
                                {scopeTarget.inherited
                                    ? same(
                                          environmentLabel(
                                              scopeTarget.inherited.environment,
                                          ),
                                      )
                                    : t('screens.inspector.screen_default')}
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
            case 'urls':
            case 'timezones': {
                // Kept as one value a line; edited as a row each, empty rows included.
                const zones = field.type === 'timezones';
                const urls =
                    raw === undefined || raw === null || raw === ''
                        ? zones
                            ? []
                            : ['']
                        : String(raw).split('\n');
                const setUrls = (list: string[]) =>
                    setOption(field.key, list.join('\n'));

                return (
                    <div
                        key={field.key}
                        className={cn('flex flex-col gap-1', span)}
                    >
                        <Label
                            htmlFor={id(`${field.key}-0`)}
                            className="text-xs text-ink-2"
                        >
                            {fieldLabel(field.key)}
                        </Label>
                        {urls.map((url, i) => (
                            <div key={i} className="flex gap-1">
                                {zones ? (
                                    <div className="min-w-0 flex-1">
                                        <TimezoneSelect
                                            id={id(`${field.key}-${i}`)}
                                            value={url}
                                            onChange={(zone) =>
                                                setUrls(
                                                    urls.map((u, j) =>
                                                        j === i ? zone : u,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                ) : (
                                    <Input
                                        id={id(`${field.key}-${i}`)}
                                        type="url"
                                        className="min-w-0 flex-1"
                                        value={url}
                                        placeholder={t(
                                            `screens.fields.${field.key}.placeholder`,
                                        )}
                                        onChange={(e) =>
                                            setUrls(
                                                urls.map((u, j) =>
                                                    j === i
                                                        ? e.target.value
                                                        : u,
                                                ),
                                            )
                                        }
                                    />
                                )}
                                {(zones || urls.length > 1) && (
                                    <Button
                                        variant="ghost"
                                        aria-label={t(
                                            zones
                                                ? 'screens.inspector.remove_zone'
                                                : 'screens.inspector.remove_url',
                                        )}
                                        onClick={() =>
                                            setUrls(
                                                urls.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        <X
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                )}
                            </div>
                        ))}
                        {urls.length < (zones ? MAX_ZONES : MAX_SLIDES) && (
                            <Button
                                variant="ghost"
                                className="self-start"
                                onClick={() => setUrls([...urls, ''])}
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                {t(
                                    zones
                                        ? 'screens.inspector.add_zone'
                                        : 'screens.inspector.add_url',
                                )}
                            </Button>
                        )}
                        <span className="text-xs text-ink-3">
                            {t(`screens.fields.${field.key}.hint`)}
                        </span>
                    </div>
                );
            }
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
                        <TimezoneSelect
                            id={id(field.key)}
                            value={String(raw ?? '')}
                            onChange={(zone) => setOption(field.key, zone)}
                            emptyLabel={t('screens.inspector.screen_timezone')}
                        />
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
                            {field.key === 'filter' && filterOn
                                ? t(`screens.fields.filter.on.${filterOn}`)
                                : fieldLabel(field.key)}
                        </Label>
                        {field.type === 'textarea' ? (
                            <textarea
                                id={id(field.key)}
                                rows={3}
                                value={String(raw ?? '')}
                                placeholder={t(
                                    `screens.fields.${field.key}.placeholder`,
                                )}
                                onChange={(e) =>
                                    setOption(field.key, e.target.value)
                                }
                                className={cn(
                                    inputClass,
                                    'h-auto resize-y py-2',
                                )}
                            />
                        ) : (
                            <Input
                                type={
                                    field.type === 'datetime'
                                        ? 'datetime-local'
                                        : undefined
                                }
                                id={id(field.key)}
                                value={String(raw ?? '')}
                                list={
                                    field.key === 'filter' && filterOn
                                        ? id(`filter-${filterOn}`)
                                        : field.key === 'domain'
                                          ? id('filter-domain')
                                          : undefined
                                }
                                placeholder={
                                    scope &&
                                    (field.key === 'filter' ||
                                        field.key === 'domain') &&
                                    scopeTarget.inherited?.[field.key]
                                        ? scopeTarget.inherited[field.key]
                                        : inherited &&
                                            typeof inherited[field.key] ===
                                                'string'
                                          ? String(inherited[field.key])
                                          : field.key === 'filter' && filterOn
                                            ? t(
                                                  `screens.fields.filter.all.${filterOn}`,
                                              )
                                            : field.key === 'caption'
                                              ? // What the line says fits what the code holds.
                                                t(
                                                    `screens.fields.caption.placeholders.${option<string | null>(catalog.schema, widget, 'content') ?? option<string>(catalog.schema, widget, 'media')}`,
                                                )
                                              : t(
                                                    `screens.fields.${field.key}.placeholder`,
                                                )
                                }
                                onChange={(e) =>
                                    scope
                                        ? scopeTarget.set(
                                              field.key as keyof WidgetScope,
                                              e.target.value,
                                          )
                                        : target.set(field.key, e.target.value)
                                }
                            />
                        )}
                        {field.key === 'filter' && filterOn && (
                            <datalist id={id(`filter-${filterOn}`)}>
                                {(catalog.filters?.[filterOn] ?? []).map(
                                    (name) => (
                                        <option
                                            key={name}
                                            value={name}
                                            label={catalog.filterLabels?.[name]}
                                        />
                                    ),
                                )}
                            </datalist>
                        )}
                        {field.key === 'domain' && (
                            <datalist id={id('filter-domain')}>
                                {(catalog.filters?.domain ?? []).map((name) => (
                                    <option key={name} value={name} />
                                ))}
                            </datalist>
                        )}
                    </div>
                );
        }
    };

    // The open value: the widget itself on the first tab, else its rotate entry.
    const valueSource = first
        ? source
        : pool.find((s) => s.id === item?.source);
    const target: Target = first
        ? own
        : {
              values: item.options ?? {},
              set: (key, value) =>
                  setItem(active - 1, {
                      options: without(item.options, key, value),
                  }),
              setMany: (changes) =>
                  setItem(active - 1, {
                      options: Object.entries(changes).reduce(
                          (options, [key, value]) =>
                              without(options, key, value),
                          item.options ?? {},
                      ),
                  }),
              source: valueSource,
              inherited: widget.options,
              prefix: `r${active}-`,
          };
    // An External JSON value's options over the widget's, as the server merges them.
    const effective = (key: string) => {
        const value = target.values[key] ?? target.inherited?.[key];

        return typeof value === 'string' ? value : '';
    };
    // A Prometheus source is read with a selector, and has no item paths.
    const prometheus =
        valueSource?.id === 'external_json' &&
        catalog.data_sources?.find((s) => s.uuid === effective('data_source'))
            ?.format === 'prometheus';
    // A service that counts whole days has no hour to pick.
    const connectedPeriods =
        valueSource?.id === 'external_json'
            ? catalog.data_sources?.find(
                  (s) => s.uuid === effective('data_source'),
              )?.periods
            : null;
    // A connected source declares its form, so there is no path to set.
    const connected =
        valueSource?.id === 'external_json' &&
        catalog.data_sources?.find((s) => s.uuid === effective('data_source'))
            ?.format === 'connected';
    const preview = useJsonPreview(
        part === 'all' ? catalog.screen : undefined,
        valueSource?.id === 'external_json' && !connected
            ? effective('data_source') || undefined
            : undefined,
        widget.type,
        {
            path: effective('json_path'),
            label_path: effective('label_path'),
            value_path: effective('value_path'),
        },
    );
    // The open value's scope: the widget's on the first tab, else its entry's over it.
    const scopeTarget: {
        values: WidgetScope;
        set: (key: keyof WidgetScope, value: unknown) => void;
        inherited?: WidgetScope;
    } = first
        ? { values: widget.scope, set: setScope }
        : {
              values: item.scope ?? {},
              set: (key, value) =>
                  setItem(active - 1, {
                      scope: without(item.scope, key, value),
                  }),
              // A filter names a route, queue, job or host: only a value that filters
              // the same kind follows the widget's (as the server does).
              inherited:
                  filterOn === source?.filter_on
                      ? widget.scope
                      : {
                            ...widget.scope,
                            filter: undefined,
                            domain: undefined,
                        },
          };
    const projectsLabel = (value: string[] | undefined) =>
        value === undefined
            ? t('screens.inspector.screen_default')
            : value.length === 0
              ? catalog.scoped
                  ? t('screens.inspector.all_screen_projects')
                  : t('screens.inspector.all_projects')
              : (catalog.projects.find((p) => p.uuid === value[0])?.name ??
                value[0]);
    const environmentLabel = (value: string | undefined) =>
        !value
            ? t('screens.inspector.screen_default')
            : value === ALL
              ? t('screens.inspector.all_environments')
              : value;
    const vid = (key: string) => id(`${target.prefix}${key}`);
    const lowIsBad =
        (target.values.low_is_bad as boolean | undefined) ??
        (widget.options.low_is_bad as boolean | undefined) ??
        valueSource?.low_is_bad;
    // A health value is named after its checks: one by name, else how many, else all.
    const checksLabel = (picked: string[]) =>
        picked.length === 0
            ? t('screens.inspector.all_checks')
            : picked.length === 1
              ? (catalog.checks.find((c) => c.uuid === picked[0])?.name ??
                t('screens.inspector.some_checks', { count: 1 }, 1))
              : t(
                    'screens.inspector.some_checks',
                    { count: picked.length },
                    picked.length,
                );
    const label = (r: RotateItem, i: number) =>
        r.title ??
        (repeats
            ? checksLabel(checksOf(r, i))
            : (pool.find((s) => s.id === r.source)?.label ?? r.source));
    const sections = schema[widget.type] ?? [];
    const [valueSection, ...rest] = sections;
    // Display lives behind the gear on the tile.
    // Scope is per value, on its tab; Display lives behind the gear on the tile.
    const whole = rest.filter(
        (s) =>
            s.id !== 'display' &&
            s.id !== 'scope' &&
            (s.id !== 'rotation' || items.length > 1),
    );
    const scopeSection = rest.find((s) => s.id === 'scope');

    if (part === 'display') {
        return (
            <div className="grid grid-cols-2 gap-2.5">
                {sections
                    .find((s) => s.id === 'display')
                    ?.fields.filter((field) => field.key !== 'refresh')
                    .map((field) => renderField(field, false))}
            </div>
        );
    }

    const value = (
        <>
            {widget.type === 'empty' && (
                <p className="text-[13px] text-ink-2">
                    {t('screens.inspector.empty_hint')}
                </p>
            )}

            {pool.length > 1 && (
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor={vid('source')}>
                        {t('screens.inspector.shows')}
                    </Label>
                    <TreeSelect
                        id={vid('source')}
                        label={t('screens.inspector.shows')}
                        options={pool
                            .filter(
                                (s) =>
                                    s.id === valueSource?.id ||
                                    !items.some((i) => i.source === s.id),
                            )
                            .map((s) => ({
                                value: s.id,
                                label: s.label,
                                path: [t(`screens.topics.${s.topic}`)],
                            }))}
                        value={(first ? widget.source : item.source) ?? ''}
                        onChange={(id) => id !== null && pickSource(id)}
                        placeholder={t('screens.inspector.shows')}
                        searchPlaceholder={t('screens.picker.search')}
                        noMatches={t('screens.picker.no_matches')}
                        startOpen
                    />
                </div>
            )}

            {widget.type !== 'empty' && (
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor={vid('title')}>
                        {t('screens.inspector.title')}
                    </Label>
                    <Input
                        id={vid('title')}
                        value={(first ? widget.title : item.title) ?? ''}
                        placeholder={
                            (repeats && item
                                ? checksLabel(checksOf(item, active))
                                : valueSource?.label) ??
                            t(`screens.kinds.${widget.type}`)
                        }
                        onChange={(e) =>
                            first
                                ? onChange({
                                      ...widget,
                                      title: e.target.value || null,
                                  })
                                : setItem(active - 1, {
                                      title: e.target.value || undefined,
                                  })
                        }
                    />
                </div>
            )}

            {/* A value that is the latest report has no period to pick. */}
            {hasPeriod &&
                !pool.find((s) => s.id === (item?.source ?? widget.source))
                    ?.snapshot && (
                    <div className="flex flex-col gap-1.5">
                        <span className="text-[13px] font-medium">
                            {t('screens.inspector.period')}
                        </span>
                        <div
                            role="radiogroup"
                            aria-label={t('screens.inspector.period')}
                            className="flex flex-wrap gap-1.5"
                        >
                            {[
                                '',
                                ...catalog.periods.filter(
                                    (p) =>
                                        !connectedPeriods ||
                                        connectedPeriods.includes(p),
                                ),
                            ].map((p) => {
                                const on =
                                    ((first
                                        ? widget.scope.period
                                        : item.scope?.period) ?? '') === p;

                                return (
                                    <button
                                        key={p || 'default'}
                                        type="button"
                                        role="radio"
                                        aria-checked={on}
                                        className={chip(on)}
                                        onClick={() =>
                                            first
                                                ? setScope('period', p)
                                                : setItem(active - 1, {
                                                      scope: p
                                                          ? { period: p }
                                                          : undefined,
                                                  })
                                        }
                                    >
                                        {p !== ''
                                            ? t(`screens.period_short.${p}`)
                                            : first
                                              ? t(
                                                    'screens.inspector.period_screen',
                                                )
                                              : same(widgetPeriod)}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

            {items.length > 1 && (
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor={vid('seconds')}>
                        {t('screens.inspector.rotate_seconds')}
                    </Label>
                    <Input
                        id={vid('seconds')}
                        type="number"
                        min={1}
                        value={String(
                            (first
                                ? (widget.options.source_seconds as
                                      | number
                                      | undefined)
                                : item.seconds) ?? '',
                        )}
                        placeholder={`${every} s`}
                        onChange={(e) => {
                            const seconds =
                                e.target.value === ''
                                    ? undefined
                                    : Number(e.target.value);

                            return first
                                ? setOption('source_seconds', seconds)
                                : setItem(active - 1, { seconds });
                        }}
                    />
                </div>
            )}

            {(widget.type === 'stat' || widget.type === 'gauge') && (
                <p className="text-xs text-ink-3">
                    {t(
                        lowIsBad
                            ? 'screens.inspector.limits_below'
                            : 'screens.inspector.limits_above',
                    )}
                    {valueSource?.unit === 'ratio' &&
                        ' ' + t('screens.inspector.ratio_hint')}
                    {valueSource?.unit === 'ms' &&
                        ' ' + t('screens.inspector.ms_hint')}
                    {valueSource?.unit === 'bytes' &&
                        ' ' + t('screens.inspector.bytes_hint')}
                </p>
            )}

            {valueSection && (
                <Section
                    key={valueSection.id}
                    title={t(`screens.sections.${valueSection.id}`)}
                    open
                >
                    {valueSection.fields
                        // External JSON's fields only for an External JSON value.
                        .filter(
                            (field) =>
                                !field.sources ||
                                field.sources.includes(valueSource?.id ?? ''),
                        )
                        .map((field) =>
                            renderField(
                                field,
                                valueSection.id === 'scope',
                                target,
                            ),
                        )}
                </Section>
            )}

            {/* A data source is its own scope: projects and filters do not apply. */}
            {scopeSection &&
                valueSource?.id !== 'external_json' &&
                valueSource?.id !== 'calendar' && (
                    <Section
                        key={scopeSection.id}
                        title={t(`screens.sections.${scopeSection.id}`)}
                        open={false}
                    >
                        {scopeSection.fields
                            // The period is Over above; a value with nothing to filter has no filter.
                            .filter(
                                (field) =>
                                    field.key !== 'period' &&
                                    (field.key !== 'filter' || filterOn) &&
                                    (field.key !== 'domain' ||
                                        filterOn === 'route'),
                            )
                            .map((field) => renderField(field, true))}
                    </Section>
                )}
        </>
    );

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

            {rotating ? (
                <div className="flex flex-col gap-3">
                    <div className="flex items-end gap-1 border-b border-line">
                        <div
                            role="tablist"
                            aria-label={t('screens.inspector.values')}
                            ref={tablist}
                            // The app's own slim scrollbar; snapping to whole tabs.
                            className="flex min-w-0 flex-1 snap-x snap-proximity scroll-px-2 gap-0.5 overflow-x-auto"
                            onKeyDown={(e) => {
                                const to = (
                                    {
                                        ArrowLeft: active - 1,
                                        ArrowRight: active + 1,
                                        Home: 0,
                                        End: items.length - 1,
                                    } as Record<string, number>
                                )[e.key];

                                if (to === undefined) {
                                    return;
                                }

                                e.preventDefault();
                                const i = (to + items.length) % items.length;
                                onTab(i);
                                e.currentTarget
                                    .querySelectorAll<HTMLElement>(
                                        '[role="tab"]',
                                    )
                                    [i]?.focus();
                            }}
                        >
                            {items.map((r, i) => (
                                <button
                                    key={i}
                                    type="button"
                                    role="tab"
                                    id={id(`tab-${i}`)}
                                    aria-selected={i === active}
                                    aria-controls={id('panel')}
                                    tabIndex={i === active ? 0 : -1}
                                    title={label(r, i)}
                                    draggable={items.length > 1}
                                    onDragStart={(e) => {
                                        e.dataTransfer.setData(
                                            TAB_DRAG,
                                            String(i),
                                        );
                                        e.dataTransfer.effectAllowed = 'move';
                                    }}
                                    onDragOver={(e) => {
                                        if (
                                            e.dataTransfer.types.includes(
                                                TAB_DRAG,
                                            )
                                        ) {
                                            e.preventDefault();
                                        }
                                    }}
                                    onDrop={(e) => {
                                        const from = Number(
                                            e.dataTransfer.getData(TAB_DRAG),
                                        );
                                        e.preventDefault();

                                        if (from !== i) {
                                            move(from, i);
                                        }
                                    }}
                                    onClick={() => onTab(i)}
                                    className={cn(
                                        'max-w-32 flex-none snap-start truncate border-b-2 px-2.5 py-1.5 text-xs',
                                        i === active
                                            ? 'border-amber font-medium text-ink'
                                            : 'border-transparent text-ink-3 hover:text-ink',
                                    )}
                                >
                                    {label(r, i)}
                                </button>
                            ))}
                        </div>
                        <button
                            type="button"
                            aria-label={t('screens.inspector.add_value')}
                            title={t('screens.inspector.add_value')}
                            disabled={!canAdd}
                            className="mb-0.5 flex-none rounded px-2 py-1 text-sm text-ink-3 hover:text-ink disabled:opacity-30"
                            onClick={addValue}
                        >
                            +
                        </button>
                    </div>

                    <p className="text-xs text-ink-3">
                        {items.length > 1
                            ? t('screens.inspector.rotate_on', {
                                  count: items.length,
                              })
                            : t('screens.inspector.rotate_off')}
                    </p>

                    <div
                        role="tabpanel"
                        id={id('panel')}
                        aria-labelledby={id(`tab-${active}`)}
                        className="flex flex-col gap-4"
                    >
                        {items.length > 1 && (
                            <div className="flex items-center gap-1">
                                {[-1, 1].map((by) => (
                                    <button
                                        key={by}
                                        type="button"
                                        disabled={
                                            active + by < 0 ||
                                            active + by >= items.length
                                        }
                                        aria-label={t(
                                            by < 0
                                                ? 'screens.inspector.move_earlier'
                                                : 'screens.inspector.move_later',
                                        )}
                                        title={t(
                                            by < 0
                                                ? 'screens.inspector.move_earlier'
                                                : 'screens.inspector.move_later',
                                        )}
                                        className="rounded border border-line px-2 py-0.5 text-xs text-ink-2 hover:text-ink disabled:opacity-30"
                                        onClick={() =>
                                            move(active, active + by)
                                        }
                                    >
                                        {by < 0 ? '←' : '→'}
                                    </button>
                                ))}
                                <Button
                                    variant="ghost"
                                    className="ml-auto text-xs text-err"
                                    onClick={() => removeValue(active)}
                                >
                                    {t('screens.inspector.remove_value')}
                                </Button>
                            </div>
                        )}
                        {value}
                    </div>
                </div>
            ) : (
                value
            )}

            <div
                className={cn(
                    'flex flex-col gap-4',
                    rotating && 'rounded-md border border-line bg-rail/40 p-3',
                )}
            >
                {rotating && (
                    <div className="flex flex-col gap-0.5">
                        <h3 className="text-[13px] font-semibold">
                            {t('screens.inspector.whole_widget')}
                        </h3>
                        <span className="text-xs text-ink-3">
                            {t('screens.inspector.whole_widget_hint')}
                        </span>
                    </div>
                )}

                {whole.map((section) => (
                    <Section
                        key={section.id}
                        title={t(`screens.sections.${section.id}`)}
                        open={false}
                    >
                        {section.fields
                            // Set per value, on its tab.
                            .filter((field) => field.key !== 'source_seconds')
                            .map((field) =>
                                renderField(field, section.id === 'scope'),
                            )}
                    </Section>
                ))}

                {/* How often it updates is about its data, so it stays here. */}
                {sections
                    .find((s) => s.id === 'display')
                    ?.fields.filter((field) => field.key === 'refresh')
                    .map((field) => (
                        <div
                            key={field.key}
                            className="grid grid-cols-2 gap-2.5"
                        >
                            {renderField(field, false)}
                        </div>
                    ))}

                {sections.some((s) => s.id === 'display') && (
                    <p className="text-xs text-ink-3">
                        {t('screens.inspector.display_hint')}
                    </p>
                )}
            </div>
        </div>
    );
}

/** Settings of the whole screen, shown while no widget is selected. */
export function ScreenSettings({
    screen,
    catalog,
    theme,
    onChange,
}: {
    screen: Screen;
    catalog: Catalog;
    /** The theme the screen shows right now (the schedule resolved). */
    theme: 'dark' | 'light';
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
                {catalog.teamProjects.map((p) => (
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
                <TimezoneSelect
                    id="screen-tz"
                    value={screen.timezone ?? ''}
                    emptyLabel={t('screens.settings.browser_timezone')}
                    onChange={(zone) => onChange({ timezone: zone || null })}
                />
                <span className="text-xs text-ink-3">
                    {t('screens.settings.timezone_hint')}
                </span>
            </div>
            <label className="flex items-center gap-2 text-[13px]">
                <Switch
                    small
                    checked={screen.show_header}
                    onChange={(on) => onChange({ show_header: on })}
                    label={t('screens.designer.show_header')}
                />
                {t('screens.designer.show_header')}
            </label>
            {screen.show_header && (
                <label className="flex items-center gap-2 text-[13px]">
                    <Switch
                        small
                        checked={screen.show_clock}
                        onChange={(on) => onChange({ show_clock: on })}
                        label={t('screens.settings.show_clock')}
                    />
                    {t('screens.settings.show_clock')}
                </label>
            )}
            {screen.show_header && screen.show_clock && (
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="screen-clock-format">
                        {t('screens.fields.format.label')}
                    </Label>
                    <Select
                        id="screen-clock-format"
                        value={screen.clock_format}
                        onChange={(e) =>
                            onChange({
                                clock_format: e.target.value as '24' | '12',
                            })
                        }
                    >
                        {(['24', '12'] as const).map((format) => (
                            <option key={format} value={format}>
                                {t(`screens.fields.format.options.${format}`)}
                            </option>
                        ))}
                    </Select>
                </div>
            )}
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="screen-theme">
                    {t('screens.designer.theme')}
                </Label>
                <Select
                    id="screen-theme"
                    value={screen.theme}
                    onChange={(e) =>
                        onChange({ theme: e.target.value as Screen['theme'] })
                    }
                >
                    {(['dark', 'light', 'schedule'] as const).map((th) => (
                        <option key={th} value={th}>
                            {t(`screens.theme.${th}`)}
                        </option>
                    ))}
                </Select>
                {screen.theme === 'schedule' && (
                    <>
                        <div className="grid grid-cols-2 gap-2.5">
                            <div className="flex flex-col gap-1">
                                <Label
                                    htmlFor="screen-light-from"
                                    className="text-xs text-ink-2"
                                >
                                    {t('screens.designer.light_from')}
                                </Label>
                                <Input
                                    id="screen-light-from"
                                    type="time"
                                    value={screen.light_from}
                                    onChange={(e) =>
                                        e.target.value &&
                                        onChange({ light_from: e.target.value })
                                    }
                                />
                            </div>
                            <div className="flex flex-col gap-1">
                                <Label
                                    htmlFor="screen-light-to"
                                    className="text-xs text-ink-2"
                                >
                                    {t('screens.designer.light_to')}
                                </Label>
                                <Input
                                    id="screen-light-to"
                                    type="time"
                                    value={screen.light_to}
                                    onChange={(e) =>
                                        e.target.value &&
                                        onChange({ light_to: e.target.value })
                                    }
                                />
                            </div>
                        </div>
                        <span className="text-xs text-ink-3">
                            {t('screens.designer.now_theme', {
                                theme: t(`screens.theme.${theme}`),
                            })}
                        </span>
                    </>
                )}
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="screen-fit">{t('screens.settings.fit')}</Label>
                <Select
                    id="screen-fit"
                    value={screen.fit}
                    onChange={(e) =>
                        onChange({ fit: e.target.value as Screen['fit'] })
                    }
                >
                    {(['keep', 'fill'] as const).map((f) => (
                        <option key={f} value={f}>
                            {t(`screens.fit.${f}`)}
                        </option>
                    ))}
                </Select>
                <span className="text-xs text-ink-3">
                    {t('screens.settings.fit_hint')}
                </span>
            </div>
        </div>
    );
}

/** Width of the gear's popover, to keep it on screen. */
const GEAR_WIDTH = 300;

/**
 * The gear in a designer tile's top right corner: the widget's Display settings in a
 * popover next to it (a native popover: Escape and a click outside close it).
 */
/**
 * Display settings copied from one widget's gear, to paste on another's. Kept for as
 * long as the page is open; the keys say which settings the widget had, so a setting
 * left at its default is pasted as the default too.
 */
type CopiedDisplay = {
    from: string;
    keys: string[];
    values: Record<string, unknown>;
};
let copiedDisplay: CopiedDisplay | null = null;
const copiedDisplayListeners = new Set<() => void>();
const subscribeCopiedDisplay = (listener: () => void) => {
    copiedDisplayListeners.add(listener);

    return () => {
        copiedDisplayListeners.delete(listener);
    };
};
const setCopiedDisplay = (value: CopiedDisplay) => {
    copiedDisplay = value;
    copiedDisplayListeners.forEach((listener) => listener());
};

/** The keys behind the gear; refresh is about the data and stays in the inspector. */
const displayKeys = (catalog: Catalog, type: Widget['type']) =>
    (catalog.schema[type] ?? [])
        .find((s) => s.id === 'display')
        ?.fields.map((field) => field.key)
        .filter((key) => key !== 'refresh') ?? [];

export function DisplayGear({
    widget,
    catalog,
    onChange,
    onOpen,
}: {
    widget: Widget;
    catalog: Catalog;
    onChange: (widget: Widget) => void;
    onOpen: () => void;
}) {
    const t = useT();
    const copied = useSyncExternalStore(
        subscribeCopiedDisplay,
        () => copiedDisplay,
    );
    const keys = displayKeys(catalog, widget.type);
    // Only what both widgets have: a gauge has no use for a list's settings.
    const pasteable =
        copied !== null && copied.from !== widget.id
            ? keys.filter((key) => copied.keys.includes(key))
            : [];
    const copy = () =>
        setCopiedDisplay({
            from: widget.id,
            keys,
            values: Object.fromEntries(
                keys
                    .filter((key) => key in widget.options)
                    .map((key) => [key, widget.options[key]]),
            ),
        });
    const paste = () => {
        const options = { ...widget.options };

        for (const key of pasteable) {
            if (copied !== null && key in copied.values) {
                options[key] = copied.values[key];
            } else {
                delete options[key];
            }
        }

        onChange({ ...widget, options });
    };
    const gear = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    // Beside the tile, so it stays in view while its settings change: right of it, else
    // left, else below or above it, whichever has more room, scrolling inside when it
    // does not fit. Written to the element right after it opens, before the browser
    // paints it, and again on every scroll or resize while open, so it stays with the
    // tile.
    const place = () => {
        const tile = gear.current?.closest('.lj-tile')?.getBoundingClientRect();
        const el = panel.current;

        if (!tile || !el) {
            return;
        }

        const gap = 6;
        const pad = 10;
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        el.style.maxHeight = '';
        const height = el.scrollHeight;
        const right = tile.right + gap + GEAR_WIDTH <= vw - pad;
        const left = tile.left - gap - GEAR_WIDTH >= pad;

        if (right || left) {
            el.style.left = `${right ? tile.right + gap : tile.left - gap - GEAR_WIDTH}px`;
            el.style.top = `${Math.max(pad, Math.min(tile.top, vh - pad - height))}px`;
            el.style.bottom = 'auto';
            el.style.maxHeight = `${vh - 2 * pad}px`;

            return;
        }

        const below = vh - tile.bottom - gap - pad;
        const above = tile.top - gap - pad;
        const down = height <= below || below >= above;

        el.style.left = `${Math.max(pad, Math.min(tile.right - GEAR_WIDTH, vw - GEAR_WIDTH - pad))}px`;
        el.style.top = down ? `${tile.bottom + gap}px` : 'auto';
        el.style.bottom = down ? 'auto' : `${vh - tile.top + gap}px`;
        el.style.maxHeight = `${Math.max(120, down ? below : above)}px`;
    };
    // While open it follows the tile through any scrolling container and resizes.
    const listener = useRef<((e?: Event) => void) | null>(null);
    const follow = (open: boolean) => {
        if (listener.current) {
            window.removeEventListener('scroll', listener.current, true);
            window.removeEventListener('resize', listener.current);
            listener.current = null;
        }

        if (open) {
            // Scrolling the panel itself does not move it.
            listener.current = (e?: Event) =>
                panel.current?.contains(e?.target as Node) || place();
            window.addEventListener('scroll', listener.current, true);
            window.addEventListener('resize', listener.current);
        }
    };
    const popover = `display-${widget.id}`;
    // Keeps the designer's tile handlers (select, drag, keys) out of the popover.
    const stop = (e: { stopPropagation: () => void }) => e.stopPropagation();

    return (
        <>
            <button
                ref={gear}
                type="button"
                popoverTarget={popover}
                aria-label={t('screens.inspector.display_gear')}
                title={t('screens.inspector.display_gear')}
                draggable={false}
                // The before: square keeps a 24px target, however small the preview draws the gear.
                className="lj-badge lj-gear relative z-20 flex size-[1.5em] cursor-pointer items-center justify-center rounded border border-(--s-line) bg-(--s-ground) text-(--s-ink-2) before:absolute before:top-1/2 before:left-1/2 before:size-6 before:-translate-1/2 hover:text-(--s-ink)"
                onPointerDown={stop}
                onClick={(e) => {
                    // Opened here rather than by popoverTarget, so it is placed before
                    // the first paint; popoverTarget still keeps a click on the gear
                    // from counting as a click outside.
                    e.preventDefault();

                    if (panel.current?.matches(':popover-open')) {
                        panel.current.hidePopover();

                        return;
                    }

                    panel.current?.showPopover();
                    place();
                    follow(true);
                    onOpen();
                }}
            >
                <Settings aria-hidden="true" className="size-[1em]" />
            </button>
            <div
                ref={panel}
                id={popover}
                popover="auto"
                role="dialog"
                aria-label={t('screens.inspector.display_gear')}
                draggable={false}
                className="m-0 cursor-auto rounded-md border border-line bg-panel p-3 text-left text-[13px] text-ink shadow-lg"
                style={{
                    position: 'fixed',
                    // Placed by place(); top, bottom and left are set there.
                    inset: 'auto',
                    overflowY: 'auto',
                    width: GEAR_WIDTH,
                }}
                onToggle={(e) => e.newState === 'closed' && follow(false)}
                onClick={stop}
                onPointerDown={stop}
                onKeyDown={stop}
                onDragStart={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                }}
            >
                <div className="mb-2.5 flex items-center justify-between gap-2">
                    <h3 className="text-[11px] font-semibold tracking-wide text-ink-3 uppercase">
                        {t('screens.sections.display')}
                    </h3>
                    <div className="flex gap-1">
                        <Button
                            variant="ghost"
                            className="h-6 px-2 text-xs"
                            onClick={copy}
                        >
                            {copied?.from === widget.id
                                ? t('screens.inspector.display_copied')
                                : t('screens.inspector.display_copy')}
                        </Button>
                        <Button
                            variant="ghost"
                            className="h-6 px-2 text-xs"
                            disabled={pasteable.length === 0}
                            onClick={paste}
                        >
                            {t('screens.inspector.display_paste')}
                        </Button>
                    </div>
                </div>
                <WidgetInspector
                    part="display"
                    widget={widget}
                    catalog={catalog}
                    onChange={onChange}
                    onRemove={() => {}}
                />
            </div>
        </>
    );
}
