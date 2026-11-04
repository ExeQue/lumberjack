/** A status screen widget as stored; see App\Domains\Screens\Support\WidgetSchema. */
export type Widget = {
    id: string;
    type: WidgetKind;
    source: string | null;
    /** More sources to show in turn: an id, or one with settings of its own. */
    rotate: (string | RotateItem)[];
    /** 0-based column and row of its top left cell. */
    x: number;
    y: number;
    w: number;
    h: number;
    title: string | null;
    options: Record<string, unknown>;
    scope: WidgetScope;
};

/** A value of a rotation with its own settings over the widget's; see WidgetSchema::rotation(). */
export type RotateItem = {
    source: string;
    /** How long it shows; absent means the widget's rotate_every. */
    seconds?: number;
    title?: string;
    /** Only fields of the type's first section (itemFieldsOf). */
    options?: Record<string, unknown>;
    /** Any scope key over the widget's; see WidgetScope. */
    scope?: Partial<WidgetScope>;
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
    /** For a source that filters a route: the domain the requests were made to. */
    domain?: string;
};

export type ScreenTheme = 'dark' | 'light' | 'schedule';

/** ScreenAspect: the display's shape, landscape; ScreenOrientation turns it on its side. */
export type ScreenOrientation = 'landscape' | 'portrait';

export type Screen = {
    uuid: string;
    name: string;
    cols: number;
    rows: number;
    /** Such as 16:9; see ScreenAspect. */
    aspect: string;
    orientation: ScreenOrientation;
    /** On a display of another shape: keep the screen's (bars around it) or fill it. */
    fit: 'keep' | 'fill';
    /** Whether the TV shows the name, scope, live state and clock above the grid. */
    show_header: boolean;
    /** Whether that header shows the clock. */
    show_clock: boolean;
    theme: ScreenTheme;
    light_from: string;
    light_to: string;
    /** Null: the viewer's own (the browser's) time zone. */
    timezone: string | null;
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
        | 'data_source'
        | 'timezone'
        | 'projects'
        | 'environment';
    options?: string[];
    default: string | boolean | null;
    data: boolean;
    span: number;
    /** Shown only for these sources (the External JSON fields). */
    sources?: string[];
};

export type SchemaSection = { id: string; fields: SchemaField[] };

export type Schema = Record<WidgetKind, SchemaSection[]>;

export type Source = {
    type: WidgetKind;
    id: string;
    label: string;
    /** What it is about, to group the designer's list. */
    topic: string;
    unit: string;
    amber: number | null;
    red: number | null;
    low_is_bad: boolean;
    metric: string | null;
    filter_on: string | null;
    /** The latest report, not a figure over a period. */
    snapshot?: boolean;
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
export const ROTATING_KINDS: WidgetKind[] = [
    'stat',
    'gauge',
    'chart',
    'list',
    'health',
];
