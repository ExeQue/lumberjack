/** The shapes of connected source each widget can show. */
const CONNECTED_SHAPES: Record<string, string[]> = {
    stat: ['number'],
    gauge: ['number'],
    chart: ['series'],
    list: ['list', 'table'],
};

import { TreeSelect } from '@/components/lj/tree-select';
import type { TreeOption } from '@/components/lj/tree-select';
import {
    AlignCenter,
    AlignLeft,
    AlignVerticalJustifyCenter,
    AlignVerticalJustifyStart,
    Expand,
    Moon,
    Plus,
    RectangleHorizontal,
    RectangleVertical,
    Scaling,
    Shrink,
    SlidersHorizontal,
    Sun,
    SunMoon,
    Settings,
    X,
} from 'lucide-react';
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
    DateFormat,
    Screen,
    ScreenSpacing,
    Source,
    Sources,
    Widget,
    WidgetScope,
} from './types';
import { datePatternProblem, kioskDate } from './frame';
import {
    DATE_FORMATS,
    HEADER_PARTS,
    MAX_SLIDES,
    MAX_ZONES,
    ROTATING_KINDS,
} from './types';

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
    /** Widgets a group holds: the group draws their card. */
    grouped?: string[];
    /** The shapes a screen can have, such as 16:9 (ScreenAspect). */
    aspects?: string[];
};

const ALL = '*';

const chip = (on: boolean) =>
    cn(
        'rounded-full border px-2.5 py-1 text-xs',
        on
            ? 'border-amber bg-amber/15 text-amber'
            : 'border-line-strong bg-rail text-ink-2 hover:text-ink',
    );

/** A letter as large as each text size draws, relative to the others. */
const SIZE_GLYPHS: Record<string, ReactNode> = Object.fromEntries(
    Object.entries({ s: 11, m: 14, l: 17, xl: 20 }).map(([size, px]) => [
        size,
        <span className="leading-none font-semibold" style={{ fontSize: px }}>
            A
        </span>,
    ]),
);

/** Options picked from icons that show what each does, with the icon per value. */
const CHOICE_ICONS: Record<string, Record<string, ReactNode>> = {
    align: { left: <AlignLeft />, center: <AlignCenter /> },
    valign: {
        top: <AlignVerticalJustifyStart />,
        middle: <AlignVerticalJustifyCenter />,
    },
    text_size: { auto: <Scaling />, ...SIZE_GLYPHS },
    title_size: SIZE_GLYPHS,
};

/** Room around the grid or between widgets, per ScreenSpacing, in the icons' units. */
const SPACING_ROOM: Record<ScreenSpacing, number> = {
    none: 0,
    s: 1.5,
    m: 3,
    l: 4.5,
};

/**
 * A screen's margin or gap as a picture: a widget inset that far in the screen, or two
 * widgets that far apart (square and on one line at none, as the screen draws them).
 */
function SpacingIcon({
    kind,
    value,
}: {
    kind: 'margin' | 'gap';
    value: ScreenSpacing;
}) {
    const room = SPACING_ROOM[value];
    const w = (14 - room) / 2;

    return (
        <svg
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.25}
            aria-hidden="true"
        >
            {kind === 'margin' ? (
                <>
                    <rect x={1} y={1} width={14} height={14} rx={2} />
                    <rect
                        x={1.6 + room}
                        y={1.6 + room}
                        width={12.8 - 2 * room}
                        height={12.8 - 2 * room}
                        rx={room === 0 ? 1.4 : 1}
                        fill="currentColor"
                        fillOpacity={0.45}
                        stroke="none"
                    />
                </>
            ) : (
                <>
                    <rect
                        x={1}
                        y={3}
                        width={w}
                        height={10}
                        rx={room === 0 ? 0 : 1.5}
                    />
                    <rect
                        x={1 + w + room}
                        y={3}
                        width={w}
                        height={10}
                        rx={room === 0 ? 0 : 1.5}
                    />
                </>
            )}
        </svg>
    );
}

/** A screen's shape as a picture: a frame of its width over its height. */
function AspectIcon({ ratio }: { ratio: number }) {
    const w = ratio >= 1 ? 14 : 14 * ratio;
    const h = ratio >= 1 ? 14 / ratio : 14;

    return (
        <svg
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.25}
            aria-hidden="true"
        >
            <rect x={8 - w / 2} y={8 - h / 2} width={w} height={h} rx={1.5} />
        </svg>
    );
}

/**
 * Values as icons, each over its name, the picked ones lit: a row of a few, or a grid of
 * many (the QR library's parts). Several values make it a pick of several.
 */
function IconChoice({
    label,
    value,
    options,
    onChange,
    grid = false,
}: {
    label: string;
    value: string | string[];
    options: { value: string; label: string; icon: ReactNode }[];
    onChange: (value: string) => void;
    grid?: boolean;
}) {
    const many = Array.isArray(value);
    const on = (v: string) => (many ? value.includes(v) : v === value);
    const box = useRef<HTMLDivElement>(null);

    // A scrolling grid shows what is picked, the first time it shows (its section opened).
    useEffect(() => {
        const el = box.current;

        if (!el) {
            return;
        }

        const seen = new ResizeObserver(() => {
            if (el.clientHeight === 0) {
                return;
            }

            seen.disconnect();
            const first = el.querySelector<HTMLElement>(
                '[aria-checked="true"], [aria-pressed="true"]',
            );

            if (first) {
                el.scrollTop =
                    first.getBoundingClientRect().top -
                    el.getBoundingClientRect().top -
                    4;
            }
        });
        seen.observe(el);

        return () => seen.disconnect();
    }, []);

    return (
        <div
            ref={box}
            role={many ? 'group' : 'radiogroup'}
            aria-label={label}
            className={cn(
                'gap-0.5 rounded-md border border-line-strong bg-rail p-0.5',
                // A long grid scrolls: about three rows show.
                grid
                    ? 'grid max-h-[13rem] grid-cols-4 overflow-y-auto overscroll-contain'
                    : 'flex',
            )}
        >
            {options.map((o) => (
                <button
                    key={o.value}
                    type="button"
                    role={many ? undefined : 'radio'}
                    aria-checked={many ? undefined : on(o.value)}
                    aria-pressed={many ? on(o.value) : undefined}
                    title={o.label}
                    onClick={() => onChange(o.value)}
                    className={cn(
                        'flex min-w-0 flex-1 cursor-pointer flex-col items-center gap-1 rounded-[5px] px-1 pt-1.5 pb-1',
                        grid ? '[&_svg]:size-6' : '[&_svg]:size-4',
                        on(o.value)
                            ? 'bg-amber/15 text-amber'
                            : 'text-ink-3 hover:text-ink',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'flex items-center',
                            grid ? 'h-7' : 'h-5',
                        )}
                    >
                        {o.icon}
                    </span>
                    <span
                        className={cn(
                            'w-full text-center text-[11px] leading-tight',
                            // A grid's names are longer: two lines rather than cut off.
                            grid ? 'line-clamp-2 break-words' : 'truncate',
                        )}
                    >
                        {o.label}
                    </span>
                </button>
            ))}
        </div>
    );
}

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

        // The QR library's parts come with pictures of their own, as SVG it ships.
        const pictures = field.icons
            ? Object.fromEntries(
                  Object.entries(field.icons).map(([v, svg]) => [
                      v,
                      <span dangerouslySetInnerHTML={{ __html: svg }} />,
                  ]),
              )
            : undefined;
        const icons = CHOICE_ICONS[field.key] ?? pictures;
        // A field's name, with a way back to the default once it is changed.
        const labelWithReset = (key: string, changed: boolean) => (
            <span className="flex items-center justify-between gap-2 text-xs text-ink-2">
                {fieldLabel(key)}
                {changed && (
                    <button
                        type="button"
                        className="text-ink-3 underline hover:text-ink"
                        onClick={() => target.set(key, null)}
                    >
                        {t('screens.inspector.reset')}
                    </button>
                )}
            </span>
        );
        const optionLabel = (v: string) =>
            field.labels?.[v] ?? t(`screens.fields.${field.key}.options.${v}`);

        if (field.type === 'color') {
            const color = typeof raw === 'string' ? raw : null;

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
                    <div className="flex items-center gap-1.5">
                        <input
                            id={id(field.key)}
                            type="color"
                            value={color ?? field.swatch ?? '#000000'}
                            onChange={(e) =>
                                target.set(field.key, e.target.value)
                            }
                            className="h-8 w-10 shrink-0 cursor-pointer rounded border border-line-strong bg-rail p-0.5"
                        />
                        {color ? (
                            <button
                                type="button"
                                className="truncate text-xs text-ink-3 underline hover:text-ink"
                                onClick={() => target.set(field.key, null)}
                            >
                                {t(`screens.fields.${field.key}.reset`)}
                            </button>
                        ) : (
                            <span className="truncate text-xs text-ink-3">
                                {t(`screens.fields.${field.key}.unset`)}
                            </span>
                        )}
                    </div>
                </div>
            );
        }

        if (field.type === 'choices' && icons) {
            const picked = Array.isArray(raw) ? raw : [];
            const max = field.max ?? Infinity;

            return (
                <div key={field.key} className="col-span-2 flex flex-col gap-1">
                    {labelWithReset(field.key, picked.length > 0)}
                    <IconChoice
                        grid
                        label={fieldLabel(field.key)}
                        value={picked}
                        options={(field.options ?? []).map((v) => ({
                            value: v,
                            label: optionLabel(v),
                            icon: icons[v],
                        }))}
                        onChange={(v) => {
                            const next = picked.includes(v)
                                ? picked.filter((p) => p !== v)
                                : [...picked, v].slice(-max);

                            target.set(
                                field.key,
                                next.length > 0 ? next : null,
                            );
                        }}
                    />
                    <span className="text-xs text-ink-3">
                        {t(`screens.fields.${field.key}.hint`, { max })}
                    </span>
                </div>
            );
        }

        if (icons && field.type === 'select' && !scope && !inherited) {
            return (
                <div key={field.key} className="col-span-2 flex flex-col gap-1">
                    {labelWithReset(
                        field.key,
                        raw !== undefined && raw !== null,
                    )}
                    <IconChoice
                        grid={(field.options ?? []).length > 5}
                        label={fieldLabel(field.key)}
                        value={String(raw ?? field.default)}
                        options={(field.options ?? []).map((v) => ({
                            value: v,
                            label: optionLabel(v),
                            icon: icons[v] ?? <SlidersHorizontal />,
                        }))}
                        onChange={(v) =>
                            target.set(
                                field.key,
                                v === field.default ? null : v,
                            )
                        }
                    />
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
                // In a group the group's card is the one drawn: none of its own to show.
                const lockedOff =
                    field.key === 'card' &&
                    (catalog.grouped ?? []).includes(widget.id);

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
                            checked={effective && !lockedOff}
                            disabled={lockedOff}
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
                        <span
                            title={
                                lockedOff
                                    ? t('screens.inspector.card_in_group')
                                    : undefined
                            }
                        >
                            {fieldLabel(field.key)}
                        </span>
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
            {(widget.type === 'empty' || widget.type === 'group') && (
                <p className="text-[13px] text-ink-2">
                    {t(`screens.inspector.${widget.type}_hint`)}
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
/**
 * Languages a screen's header can write the date in, besides the app's and the TV's: a
 * language alone, or by country where they write dates differently.
 */
const LOCALES = [
    'da',
    'sv',
    'nb',
    'fi',
    'is',
    'en-GB',
    'en-US',
    'en-AU',
    'de-DE',
    'de-AT',
    'de-CH',
    'fr-FR',
    'fr-BE',
    'fr-CA',
    'nl-NL',
    'nl-BE',
    'es-ES',
    'es-MX',
    'pt-PT',
    'pt-BR',
    'it',
    'pl',
    'cs',
    'et',
    'lv',
    'lt',
    'ja',
    'zh-CN',
];

/** A language's or country's name, in the page's language. */
function displayName(code: string, type: 'language' | 'region'): string {
    try {
        return (
            new Intl.DisplayNames([document.documentElement.lang || 'en'], {
                type,
            }).of(code) ?? code
        );
    } catch {
        return code;
    }
}

/**
 * The languages for a tree: the app's and the TV's own under Automatic (app stands for
 * none), then every language, those with countries by country under its name.
 */
function localeOptions(
    automatic: string,
    app: string,
    browser: string,
    languages: string,
): TreeOption[] {
    const base = (code: string) => code.split('-')[0];

    return [
        { value: 'app', label: app, path: [automatic] },
        { value: 'browser', label: browser, path: [automatic] },
        ...LOCALES.map((code) => {
            const [language, region] = code.split('-');
            const countries = LOCALES.filter(
                (c) => base(c) === language,
            ).length;

            return countries > 1 && region
                ? {
                      value: code,
                      label: displayName(region, 'region'),
                      path: [languages, displayName(language, 'language')],
                      keywords: code,
                  }
                : {
                      value: code,
                      label: displayName(code, 'language'),
                      path: [languages],
                      keywords: code,
                  };
        }),
    ];
}

const SETTINGS_TABS = ['display', 'appearance', 'data', 'time'] as const;
type SettingsTab = (typeof SETTINGS_TABS)[number];

/** The settings tab open last, kept while the designer is open: back from a widget, it is still there. */
let lastSettingsTab: SettingsTab = 'display';

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
    const hint = 'col-span-2 text-xs text-ink-3';
    const [tab, setTab] = useState(lastSettingsTab);
    const pickTab = (id: SettingsTab) => {
        lastSettingsTab = id;
        setTab(id);
    };
    const setProjects = (projects: string[]) =>
        onChange({ scope: { ...scope, projects } });
    const patternProblem =
        screen.header.date_format === 'custom'
            ? datePatternProblem(screen.header.date_pattern ?? '')
            : null;
    const setHeader = (changes: Partial<Screen['header']>) =>
        onChange({ header: { ...screen.header, ...changes } });

    return (
        <div className="flex flex-col gap-3">
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

            <div
                role="tablist"
                aria-label={t('screens.settings.title')}
                className="flex border-b border-line"
            >
                {SETTINGS_TABS.map((id) => (
                    <button
                        key={id}
                        id={`screen-tab-${id}-button`}
                        type="button"
                        role="tab"
                        aria-selected={tab === id}
                        aria-controls={`screen-tab-${id}`}
                        onClick={() => pickTab(id)}
                        className={cn(
                            '-mb-px flex-1 cursor-pointer border-b-2 px-1 pb-2 text-[13px] font-medium',
                            tab === id
                                ? 'border-amber text-ink'
                                : 'border-transparent text-ink-3 hover:text-ink',
                        )}
                    >
                        {t(`screens.settings.sections.${id}`)}
                    </button>
                ))}
            </div>

            {tab === 'display' && (
                <div
                    role="tabpanel"
                    id="screen-tab-display"
                    aria-labelledby="screen-tab-display-button"
                    className="grid grid-cols-2 gap-2.5"
                >
                    <div className="col-span-2 flex flex-col gap-1">
                        <span className="text-xs text-ink-2">
                            {t('screens.settings.fit')}
                        </span>
                        <IconChoice
                            label={t('screens.settings.fit')}
                            value={screen.fit}
                            options={[
                                {
                                    value: 'keep',
                                    label: t('screens.fit.keep'),
                                    icon: <Shrink />,
                                },
                                {
                                    value: 'fill',
                                    label: t('screens.fit.fill'),
                                    icon: <Expand />,
                                },
                            ]}
                            onChange={(v) =>
                                onChange({ fit: v as Screen['fit'] })
                            }
                        />
                    </div>
                    <span className={hint}>
                        {t('screens.settings.fit_hint')}
                    </span>
                    <div className="col-span-2 flex flex-col gap-1">
                        <span className="text-xs text-ink-2">
                            {t('screens.designer.aspect')}
                        </span>
                        <IconChoice
                            grid
                            label={t('screens.designer.aspect')}
                            value={screen.aspect}
                            options={(catalog.aspects ?? [screen.aspect]).map(
                                (a) => {
                                    const [w, h] = a.split(':').map(Number);

                                    return {
                                        value: a,
                                        label: t(`screens.aspect.${a}`),
                                        icon: (
                                            <AspectIcon
                                                ratio={
                                                    screen.orientation ===
                                                    'portrait'
                                                        ? h / w
                                                        : w / h
                                                }
                                            />
                                        ),
                                    };
                                },
                            )}
                            onChange={(v) => onChange({ aspect: v })}
                        />
                    </div>
                    <div className="col-span-2 flex flex-col gap-1">
                        <span className="text-xs text-ink-2">
                            {t('screens.designer.orientation')}
                        </span>
                        <IconChoice
                            label={t('screens.designer.orientation')}
                            value={screen.orientation}
                            options={(['landscape', 'portrait'] as const).map(
                                (o) => ({
                                    value: o,
                                    label: t(`screens.orientation.${o}`),
                                    icon:
                                        o === 'landscape' ? (
                                            <RectangleHorizontal />
                                        ) : (
                                            <RectangleVertical />
                                        ),
                                }),
                            )}
                            onChange={(v) =>
                                onChange({
                                    orientation: v as Screen['orientation'],
                                })
                            }
                        />
                    </div>
                </div>
            )}

            {tab === 'appearance' && (
                <div
                    role="tabpanel"
                    id="screen-tab-appearance"
                    aria-labelledby="screen-tab-appearance-button"
                    className="grid grid-cols-2 gap-2.5"
                >
                    <div className="col-span-2 flex flex-col gap-1">
                        <span className="text-xs text-ink-2">
                            {t('screens.designer.theme')}
                        </span>
                        <IconChoice
                            label={t('screens.designer.theme')}
                            value={screen.theme}
                            options={[
                                {
                                    value: 'dark',
                                    label: t('screens.theme.dark'),
                                    icon: <Moon />,
                                },
                                {
                                    value: 'light',
                                    label: t('screens.theme.light'),
                                    icon: <Sun />,
                                },
                                {
                                    value: 'schedule',
                                    label: t('screens.settings.schedule_short'),
                                    icon: <SunMoon />,
                                },
                            ]}
                            onChange={(v) =>
                                onChange({ theme: v as Screen['theme'] })
                            }
                        />
                    </div>
                    {screen.theme === 'schedule' && (
                        <>
                            {(['light_from', 'light_to'] as const).map(
                                (key) => (
                                    <div
                                        key={key}
                                        className="flex flex-col gap-1"
                                    >
                                        <Label
                                            htmlFor={`screen-${key}`}
                                            className="text-xs text-ink-2"
                                        >
                                            {t(`screens.designer.${key}`)}
                                        </Label>
                                        <Input
                                            id={`screen-${key}`}
                                            type="time"
                                            value={screen[key]}
                                            onChange={(e) =>
                                                e.target.value &&
                                                onChange({
                                                    [key]: e.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                ),
                            )}
                            <span className={hint}>
                                {t('screens.theme.schedule')} ·{' '}
                                {t('screens.designer.now_theme', {
                                    theme: t(`screens.theme.${theme}`),
                                })}
                            </span>
                        </>
                    )}
                    {(['margin', 'gap'] as const).map((key) => (
                        <div
                            key={key}
                            className="col-span-2 flex flex-col gap-1"
                        >
                            <span className="text-xs text-ink-2">
                                {t(`screens.settings.${key}`)}
                            </span>
                            <IconChoice
                                label={t(`screens.settings.${key}`)}
                                value={screen[key]}
                                options={(['none', 's', 'm', 'l'] as const).map(
                                    (s) => ({
                                        value: s,
                                        label: t(`screens.spacing.${s}`),
                                        icon: (
                                            <SpacingIcon kind={key} value={s} />
                                        ),
                                    }),
                                )}
                                onChange={(v) =>
                                    onChange({ [key]: v as ScreenSpacing })
                                }
                            />
                        </div>
                    ))}
                </div>
            )}

            {tab === 'data' && (
                <div
                    role="tabpanel"
                    id="screen-tab-data"
                    aria-labelledby="screen-tab-data-button"
                    className="grid grid-cols-2 gap-2.5"
                >
                    <div
                        role="group"
                        aria-label={t('screens.settings.projects')}
                        className="col-span-2 flex flex-col gap-1"
                    >
                        <span className="text-xs text-ink-2">
                            {t('screens.settings.projects')}
                        </span>
                        <div className="grid grid-cols-2 gap-x-2.5 gap-y-2 pt-0.5">
                            {/* None picked is every project of the team, new ones included. */}
                            <label className="col-span-2 flex items-center gap-2 text-[13px]">
                                <Switch
                                    small
                                    checked={scope.projects.length === 0}
                                    label={t('screens.settings.all_projects')}
                                    onChange={(all) =>
                                        setProjects(
                                            all
                                                ? []
                                                : catalog.teamProjects.map(
                                                      (p) => p.uuid,
                                                  ),
                                        )
                                    }
                                />
                                {t('screens.settings.all_projects')}
                            </label>
                            {catalog.teamProjects.map((p) => (
                                <label
                                    key={p.uuid}
                                    className="flex min-w-0 items-center gap-2 text-[13px]"
                                >
                                    <Switch
                                        small
                                        checked={scope.projects.includes(
                                            p.uuid,
                                        )}
                                        label={p.name}
                                        onChange={(on) =>
                                            setProjects(
                                                on
                                                    ? [
                                                          ...scope.projects,
                                                          p.uuid,
                                                      ]
                                                    : scope.projects.filter(
                                                          (u) => u !== p.uuid,
                                                      ),
                                            )
                                        }
                                    />
                                    <span className="truncate">{p.name}</span>
                                </label>
                            ))}
                        </div>
                    </div>
                    <span className={hint}>
                        {t('screens.settings.projects_hint')}
                    </span>
                    <div className="flex flex-col gap-1">
                        <Label
                            htmlFor="screen-env"
                            className="text-xs text-ink-2"
                        >
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
            )}

            {tab === 'time' && (
                <div
                    role="tabpanel"
                    id="screen-tab-time"
                    aria-labelledby="screen-tab-time-button"
                    className="grid grid-cols-2 gap-2.5"
                >
                    <label className="col-span-2 flex items-center gap-2 text-[13px]">
                        <Switch
                            small
                            checked={screen.show_header}
                            onChange={(on) => onChange({ show_header: on })}
                            label={t('screens.designer.show_header')}
                        />
                        {t('screens.designer.show_header')}
                    </label>
                    {screen.show_header && (
                        <div className="col-span-2 flex flex-col gap-1">
                            <span className="text-xs text-ink-2">
                                {t('screens.settings.header_size')}
                            </span>
                            <IconChoice
                                label={t('screens.settings.header_size')}
                                value={screen.header.size}
                                options={(['s', 'm', 'l', 'xl'] as const).map(
                                    (size) => ({
                                        value: size,
                                        label: t(
                                            `screens.fields.title_size.options.${size}`,
                                        ),
                                        icon: SIZE_GLYPHS[size],
                                    }),
                                )}
                                onChange={(v) =>
                                    setHeader({
                                        size: v as Screen['header']['size'],
                                    })
                                }
                            />
                        </div>
                    )}
                    {screen.show_header && (
                        <>
                            <div className="col-span-2 flex flex-col gap-1">
                                <Label
                                    htmlFor="screen-header-title"
                                    className="text-xs text-ink-2"
                                >
                                    {t('screens.settings.header_title')}
                                </Label>
                                <Input
                                    id="screen-header-title"
                                    value={screen.header.title ?? ''}
                                    placeholder={screen.name}
                                    maxLength={100}
                                    onChange={(e) =>
                                        setHeader({
                                            title: e.target.value || null,
                                        })
                                    }
                                />
                            </div>
                            <div
                                role="group"
                                aria-label={t('screens.settings.header_shows')}
                                className="col-span-2 flex flex-col gap-1"
                            >
                                <span className="text-xs text-ink-2">
                                    {t('screens.settings.header_shows')}
                                </span>
                                <div className="grid grid-cols-2 gap-x-2.5 gap-y-2 pt-0.5">
                                    {HEADER_PARTS.map((part) => {
                                        const on =
                                            !screen.header.hide.includes(part);
                                        const label = t(
                                            `screens.settings.header_parts.${part}`,
                                        );

                                        return (
                                            <label
                                                key={part}
                                                className="flex items-center gap-2 text-[13px]"
                                            >
                                                <Switch
                                                    small
                                                    checked={on}
                                                    label={label}
                                                    onChange={(show) =>
                                                        setHeader({
                                                            hide: show
                                                                ? screen.header.hide.filter(
                                                                      (p) =>
                                                                          p !==
                                                                          part,
                                                                  )
                                                                : [
                                                                      ...screen
                                                                          .header
                                                                          .hide,
                                                                      part,
                                                                  ],
                                                        })
                                                    }
                                                />
                                                {label}
                                            </label>
                                        );
                                    })}
                                </div>
                                <span className="text-xs text-ink-3">
                                    {t('screens.settings.header_shows_hint')}
                                </span>
                            </div>
                            <label className="col-span-2 flex items-center gap-2 text-[13px]">
                                <Switch
                                    small
                                    checked={screen.show_clock}
                                    onChange={(on) =>
                                        onChange({ show_clock: on })
                                    }
                                    label={t('screens.settings.show_clock')}
                                />
                                {t('screens.settings.show_clock')}
                            </label>
                        </>
                    )}
                    {screen.show_header && screen.show_clock && (
                        <>
                            <div className="col-span-2 flex flex-col gap-1">
                                <span className="text-xs text-ink-2">
                                    {t('screens.fields.format.label')}
                                </span>
                                <IconChoice
                                    label={t('screens.fields.format.label')}
                                    value={screen.clock_format}
                                    options={(['24', '12'] as const).map(
                                        (format) => ({
                                            value: format,
                                            label: t(
                                                `screens.fields.format.options.${format}`,
                                            ),
                                            icon: (
                                                <span className="text-[13px] leading-none font-semibold">
                                                    {format}
                                                </span>
                                            ),
                                        }),
                                    )}
                                    onChange={(v) =>
                                        onChange({
                                            clock_format: v as '24' | '12',
                                        })
                                    }
                                />
                            </div>
                            {(['date', 'seconds'] as const).map((key) => (
                                <label
                                    key={key}
                                    className="flex items-center gap-2 text-[13px]"
                                >
                                    <Switch
                                        small
                                        checked={screen.header[key]}
                                        onChange={(on) =>
                                            setHeader({ [key]: on })
                                        }
                                        label={t(
                                            `screens.settings.clock_${key}`,
                                        )}
                                    />
                                    {t(`screens.settings.clock_${key}`)}
                                </label>
                            ))}
                            {screen.header.date && (
                                <>
                                    <div className="col-span-2 flex flex-col gap-1">
                                        <Label
                                            htmlFor="screen-date-format"
                                            className="text-xs text-ink-2"
                                        >
                                            {t('screens.settings.date_format')}
                                        </Label>
                                        <Select
                                            id="screen-date-format"
                                            value={screen.header.date_format}
                                            onChange={(e) =>
                                                setHeader({
                                                    date_format: e.target
                                                        .value as DateFormat,
                                                })
                                            }
                                        >
                                            {DATE_FORMATS.map((format) => (
                                                <option
                                                    key={format}
                                                    value={format}
                                                >
                                                    {format === 'custom'
                                                        ? t(
                                                              'screens.settings.date_custom',
                                                          )
                                                        : kioskDate(
                                                              {
                                                                  ...screen,
                                                                  header: {
                                                                      ...screen.header,
                                                                      date_format:
                                                                          format,
                                                                  },
                                                              },
                                                              Date.now(),
                                                          )}
                                                </option>
                                            ))}
                                        </Select>
                                    </div>
                                    {screen.header.date_format === 'custom' && (
                                        <div className="col-span-2 flex flex-col gap-1">
                                            <Label
                                                htmlFor="screen-date-pattern"
                                                className="text-xs text-ink-2"
                                            >
                                                {t(
                                                    'screens.settings.date_pattern',
                                                )}
                                            </Label>
                                            <Input
                                                id="screen-date-pattern"
                                                value={
                                                    screen.header
                                                        .date_pattern ?? ''
                                                }
                                                placeholder="ddd D. MMM"
                                                maxLength={60}
                                                aria-invalid={
                                                    patternProblem !== null
                                                }
                                                aria-describedby="screen-date-pattern-hint"
                                                onChange={(e) =>
                                                    setHeader({
                                                        date_pattern:
                                                            e.target.value ||
                                                            null,
                                                    })
                                                }
                                            />
                                            <span
                                                id="screen-date-pattern-hint"
                                                className={cn(
                                                    'text-xs',
                                                    patternProblem !== null
                                                        ? 'text-red'
                                                        : 'text-ink-3',
                                                )}
                                            >
                                                {patternProblem === null
                                                    ? t(
                                                          'screens.settings.date_pattern_hint',
                                                          {
                                                              example:
                                                                  kioskDate(
                                                                      screen,
                                                                      Date.now(),
                                                                  ) ?? '',
                                                          },
                                                      )
                                                    : patternProblem === ''
                                                      ? t(
                                                            'screens.validation.date_empty',
                                                        )
                                                      : t(
                                                            'screens.validation.date_token',
                                                            {
                                                                token: patternProblem,
                                                            },
                                                        )}
                                            </span>
                                        </div>
                                    )}
                                    <div className="col-span-2 flex flex-col gap-1">
                                        <Label
                                            htmlFor="screen-locale"
                                            className="text-xs text-ink-2"
                                        >
                                            {t('screens.settings.locale')}
                                        </Label>
                                        <TreeSelect
                                            id="screen-locale"
                                            label={t('screens.settings.locale')}
                                            value={
                                                screen.header.locale ?? 'app'
                                            }
                                            options={localeOptions(
                                                t(
                                                    'screens.settings.locale_automatic',
                                                ),
                                                t(
                                                    'screens.settings.locale_app',
                                                ),
                                                t(
                                                    'screens.settings.locale_browser',
                                                ),
                                                t(
                                                    'screens.settings.locale_languages',
                                                ),
                                            )}
                                            onChange={(locale) =>
                                                setHeader({
                                                    locale:
                                                        locale &&
                                                        locale !== 'app'
                                                            ? locale
                                                            : null,
                                                })
                                            }
                                            placeholder={t(
                                                'screens.settings.locale_app',
                                            )}
                                            searchPlaceholder={t(
                                                'screens.picker.search',
                                            )}
                                            noMatches={t(
                                                'screens.picker.no_matches',
                                            )}
                                        />
                                    </div>
                                </>
                            )}
                        </>
                    )}
                    <div className="col-span-2 flex flex-col gap-1">
                        <Label
                            htmlFor="screen-tz"
                            className="text-xs text-ink-2"
                        >
                            {t('screens.settings.timezone')}
                        </Label>
                        <TimezoneSelect
                            id="screen-tz"
                            value={screen.timezone ?? ''}
                            emptyLabel={t('screens.settings.browser_timezone')}
                            onChange={(zone) =>
                                onChange({ timezone: zone || null })
                            }
                        />
                    </div>
                    <span className={hint}>
                        {t('screens.settings.timezone_hint')}
                    </span>
                </div>
            )}
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
