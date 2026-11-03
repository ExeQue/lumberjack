/** A status screen widget as stored; see App\Domains\Screens\Support\WidgetSchema. */
export type Widget = {
    id: string;
    type: WidgetKind;
    source: string | null;
    rotate: string[];
    /** 0-based column and row of its top left cell. */
    x: number;
    y: number;
    w: number;
    h: number;
    title: string | null;
    options: Record<string, unknown>;
    scope: WidgetScope;
};

export type WidgetKind =
    | 'stat'
    | 'gauge'
    | 'chart'
    | 'list'
    | 'health'
    | 'clock'
    | 'empty';

export type WidgetScope = {
    projects?: string[];
    environment?: string;
    period?: string;
    release?: string;
    filter?: string;
};

export type ScreenTheme = 'dark' | 'light' | 'schedule';

export type Screen = {
    uuid: string;
    name: string;
    cols: number;
    rows: number;
    theme: ScreenTheme;
    light_from: string;
    light_to: string;
    timezone: string;
    current_theme: 'dark' | 'light';
    scope: { projects: string[]; environment: string | null; period: string };
    widgets: Widget[];
    updated_at: string | null;
};

export type SchemaField = {
    key: string;
    type:
        | 'select'
        | 'toggle'
        | 'text'
        | 'number'
        | 'checks'
        | 'timezone'
        | 'projects'
        | 'environment';
    options?: string[];
    default: string | boolean | null;
    data: boolean;
    span: number;
};

export type SchemaSection = { id: string; fields: SchemaField[] };

export type Schema = Record<WidgetKind, SchemaSection[]>;

export type Source = {
    type: WidgetKind;
    id: string;
    label: string;
    unit: string;
    amber: number | null;
    red: number | null;
    low_is_bad: boolean;
    metric: string | null;
    filter_on: string | null;
};

export type Sources = Partial<Record<WidgetKind, Source[]>>;

/** A tile's state, which colors it. */
export type Status = 'ok' | 'degraded' | 'down' | 'recovering' | 'stale' | null;

/** What the data endpoint answers per widget: per source, the payload or null. */
export type WidgetData = Record<string, Record<string, unknown> | null>;

export type ScreenData = Record<string, WidgetData>;

export const DATA_KINDS: WidgetKind[] = [
    'stat',
    'gauge',
    'chart',
    'list',
    'health',
];
export const LAYOUT_KINDS: WidgetKind[] = ['clock', 'empty'];
export const ROTATING_KINDS: WidgetKind[] = ['stat', 'gauge', 'chart', 'list'];
