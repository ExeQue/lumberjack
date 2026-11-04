<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use App\Domains\Screens\Enums\ReleaseScope;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Models\Screen;
use App\Domains\Shared\Enums\Period;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The shape of a status screen widget and the options each type takes: one schema that
 * the designer renders (it gets all() as a prop), the server validates against (rules())
 * and stores through (clean()), so client and server agree.
 *
 * A widget is {id, type, source, rotate, x, y, w, h, title, options, scope}, x and y its
 * 0-based column and row (see ScreenLayout). Data types are the
 * WidgetType cases and show a source of WidgetSources; clock and empty need none. Options
 * live in sections of fields: select (one of its values), toggle, text, number, checks
 * (health check uuids), data_source (a data source uuid). A field with sources shows only
 * for those sources (the External JSON fields). A field marked data is read by ResolveWidgetDataAction, so a
 * change to it refetches the widget; the rest only change how it draws. Scope fields are
 * stored in the widget's scope, not its options; absent means the screen's default.
 *
 * A rotating widget's rotate entries are source ids, or {source, seconds, title, options,
 * scope} where a value of the rotation has settings of its own: options only from the
 * type's first section (ITEM_SECTION: value, scale, chart, rows or checks) and the whole
 * scope (projects, environment, period, release, filter, domain), each over the widget's. The widget's source is the rotation's first value; its
 * own time on screen is the source_seconds option. Health has the one source, so its
 * values repeat it and differ by their checks (REPEATING_TYPES), which a value picks for
 * itself: none picked shows every check, never the widget's.
 */
final class WidgetSchema
{
    /** Widgets drawn by the client alone, without a source. */
    public const array LAYOUT_TYPES = ['clock', 'empty'];

    /** Types that can rotate through several sources on one tile. */
    public const array ROTATING_TYPES = ['stat', 'gauge', 'chart', 'list', 'health'];

    /** Rotating types whose values may repeat a source, told apart by their options. */
    public const array REPEATING_TYPES = ['health'];

    public const int MAX_WIDGETS = 48;

    public const int MAX_ROTATE = 12;

    /** Longest a rotated value may stay on screen, in seconds. */
    public const int MAX_ROTATE_SECONDS = 3600;

    /** Rows a list asks for when it fits them to its tile. */
    public const int LIST_FIT_LIMIT = 20;

    /** A widget scope value meaning every environment, whatever the screen's default. */
    public const string ALL_ENVIRONMENTS = '*';

    /** @var array<string, list<array{id: string, fields: list<array<string, mixed>>}>>|null */
    private static ?array $sections = null;

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [...array_map(fn (WidgetType $t): string => $t->value, WidgetType::cases()), ...self::LAYOUT_TYPES];
    }

    public static function isDataType(string $type): bool
    {
        return WidgetType::tryFrom($type) !== null;
    }

    /**
     * Every type's sections, for the designer.
     *
     * @return array<string, list<array{id: string, fields: list<array<string, mixed>>}>>
     */
    public static function all(): array
    {
        return self::$sections ??= self::build();
    }

    /**
     * @return list<array{id: string, fields: list<array<string, mixed>>}>
     */
    public static function sections(string $type): array
    {
        return self::all()[$type] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>  option fields of the type by key, scope excluded
     */
    public static function fields(string $type): array
    {
        $fields = [];

        foreach (self::sections($type) as $section) {
            if ($section['id'] === 'scope') {
                continue;
            }

            foreach ($section['fields'] as $field) {
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }

    /**
     * The option fields a rotated value can set for itself: its type's first section.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function itemFields(string $type): array
    {
        return array_column(self::sections($type)[0]['fields'] ?? [], null, 'key');
    }

    /**
     * What a rotating widget shows in turn, the source first: each value with its own
     * seconds (null for the widget's rotate_every), title, options and scope over the
     * widget's. A widget that does not rotate shows its source alone.
     *
     * @param  array<string, mixed>  $widget  as stored
     * @return list<array{source: string, seconds: int|null, title: string|null, options: array<string, mixed>, scope: array<string, mixed>}>
     */
    public static function rotation(array $widget): array
    {
        $source = (string) ($widget['source'] ?? '');
        $seconds = $widget['options']['source_seconds'] ?? null;
        $items = [['source' => $source, 'seconds' => $seconds !== null ? (int) $seconds : null, 'title' => null, 'options' => [], 'scope' => []]];
        $repeats = in_array($widget['type'] ?? null, self::REPEATING_TYPES, true);

        foreach (in_array($widget['type'] ?? null, self::ROTATING_TYPES, true) ? (array) ($widget['rotate'] ?? []) : [] as $entry) {
            $entry = is_array($entry) ? $entry : ['source' => (string) $entry];

            if (! isset($entry['source']) || (! $repeats && in_array($entry['source'], array_column($items, 'source'), true))) {
                continue;
            }

            $options = (array) ($entry['options'] ?? []);

            $items[] = [
                'source' => (string) $entry['source'],
                'seconds' => isset($entry['seconds']) ? (int) $entry['seconds'] : null,
                'title' => $entry['title'] ?? null,
                // A repeated health value's checks are its own: none picked means all of them.
                'options' => $repeats ? [...$options, 'checks' => $options['checks'] ?? []] : $options,
                'scope' => (array) ($entry['scope'] ?? []),
            ];
        }

        return $items;
    }

    /**
     * The options a widget is resolved with: the stored ones over the defaults.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function options(string $type, array $options): array
    {
        $defaults = array_map(fn (array $field): mixed => $field['default'], self::fields($type));

        return array_merge(array_filter($defaults, fn (mixed $v): bool => $v !== null), $options);
    }

    /**
     * Validation rules for one widget at $prefix ("widgets.3" or "widget").
     *
     * @param  list<string>  $projects  uuids of the team's projects
     * @return array<string, mixed>
     */
    public static function rules(string $prefix, mixed $widget, int $cols, int $rows, array $projects): array
    {
        $type = is_array($widget) && is_string($widget['type'] ?? null) ? $widget['type'] : '';
        $data = WidgetType::tryFrom($type);
        $source = fn (string $attribute, mixed $value, Closure $fail) => $data !== null && is_string($value) && WidgetSources::has($data, $value)
            ? null
            : $fail(__('ui/screens.validation.source'));
        // A rotated value is a source id, or an object naming one with its own settings.
        $item = fn (string $attribute, mixed $value, Closure $fail) => $source($attribute, is_array($value) ? ($value['source'] ?? null) : $value, $fail);

        $rules = [
            "{$prefix}" => ['required', 'array'],
            "{$prefix}.id" => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            "{$prefix}.type" => ['required', 'string', Rule::in(self::types())],
            "{$prefix}.source" => $data !== null ? ['required', 'string', $source] : ['nullable'],
            "{$prefix}.rotate" => ['nullable', 'array', 'max:'.self::MAX_ROTATE],
            "{$prefix}.rotate.*" => [$item],
            "{$prefix}.rotate.*.seconds" => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_ROTATE_SECONDS],
            "{$prefix}.rotate.*.title" => ['nullable', 'string', 'max:100'],
            "{$prefix}.rotate.*.options" => ['nullable', 'array'],
            "{$prefix}.rotate.*.scope" => ['nullable', 'array'],
            "{$prefix}.rotate.*.scope.projects" => ['nullable', 'array'],
            "{$prefix}.rotate.*.scope.projects.*" => ['string', Rule::in($projects)],
            "{$prefix}.rotate.*.scope.environment" => ['nullable', 'string', 'max:64'],
            "{$prefix}.rotate.*.scope.period" => ['nullable', Rule::enum(Period::class)],
            "{$prefix}.rotate.*.scope.release" => ['nullable', Rule::enum(ReleaseScope::class)],
            "{$prefix}.rotate.*.scope.filter" => ['nullable', 'string', 'max:200'],
            "{$prefix}.rotate.*.scope.domain" => ['nullable', 'string', 'max:255'],
            // Without a position (an older designer) it flows like before; see ScreenLayout.
            "{$prefix}.x" => ['nullable', 'integer', 'min:0', 'max:'.(Screen::MAX_COLS - 1)],
            "{$prefix}.y" => ['nullable', 'integer', 'min:0', 'max:'.ScreenLayout::MAX_Y],
            "{$prefix}.w" => ['required', 'integer', 'min:1', 'max:'.$cols],
            "{$prefix}.h" => ['required', 'integer', 'min:1', 'max:'.$rows],
            "{$prefix}.title" => ['nullable', 'string', 'max:100'],
            "{$prefix}.options" => ['nullable', 'array'],
            "{$prefix}.scope" => ['nullable', 'array'],
            "{$prefix}.scope.projects" => ['nullable', 'array'],
            "{$prefix}.scope.projects.*" => ['string', Rule::in($projects)],
            "{$prefix}.scope.environment" => ['nullable', 'string', 'max:64'],
            "{$prefix}.scope.period" => ['nullable', Rule::enum(Period::class)],
            "{$prefix}.scope.release" => ['nullable', Rule::enum(ReleaseScope::class)],
            "{$prefix}.scope.filter" => ['nullable', 'string', 'max:200'],
            "{$prefix}.scope.domain" => ['nullable', 'string', 'max:255'],
        ];

        $fields = [
            ...array_map(fn (array $field): array => [$field, "{$prefix}.options.{$field['key']}"], array_values(self::fields($type))),
            ...array_map(fn (array $field): array => [$field, "{$prefix}.rotate.*.options.{$field['key']}"], in_array($type, self::ROTATING_TYPES, true) ? array_values(self::itemFields($type)) : []),
        ];

        foreach ($fields as [$field, $attribute]) {
            $rules[$attribute] = match ($field['type']) {
                'select' => ['nullable', Rule::in($field['options'])],
                'toggle' => ['nullable', 'boolean'],
                'number' => ['nullable', 'numeric', ...(isset($field['min']) ? ['min:'.$field['min'], 'max:'.$field['max']] : [])],
                'checks' => ['nullable', 'array', 'max:50'],
                'data_source' => ['nullable', 'string', 'uuid'],
                'timezone' => ['nullable', 'timezone:all'],
                default => ['nullable', 'string', 'max:200'],
            };

            if ($field['type'] === 'checks') {
                $rules["{$attribute}.*"] = ['string', 'uuid'];
            }
        }

        return $rules;
    }

    /**
     * A validated widget as it is stored: known keys only, options and scope trimmed to
     * what its type takes, empty values left out so the defaults apply.
     *
     * @param  array<string, mixed>  $widget  positioned (ScreenLayout::positioned())
     * @return array{id: string, type: string, source: string|null, rotate: list<string|array<string, mixed>>, x: int, y: int, w: int, h: int, title: string|null, options: array<string, mixed>, scope: array<string, mixed>}
     */
    public static function clean(array $widget, int $cols, int $rows): array
    {
        $type = (string) $widget['type'];
        $source = self::isDataType($type) ? (string) $widget['source'] : null;
        $rotate = [];
        $repeats = in_array($type, self::REPEATING_TYPES, true);

        foreach (in_array($type, self::ROTATING_TYPES, true) ? (array) ($widget['rotate'] ?? []) : [] as $entry) {
            $entry = is_array($entry) ? $entry : ['source' => $entry];
            $id = (string) ($entry['source'] ?? '');

            if ($id === '' || (! $repeats && ($id === $source || in_array($id, array_map(fn (string|array $r): string => is_array($r) ? $r['source'] : $r, $rotate), true)))) {
                continue;
            }

            $own = array_filter([
                'seconds' => isset($entry['seconds']) && $entry['seconds'] !== '' ? max(1, min(self::MAX_ROTATE_SECONDS, (int) $entry['seconds'])) : null,
                'title' => is_string($entry['title'] ?? null) && trim($entry['title']) !== '' ? trim($entry['title']) : null,
                'options' => self::cleanOptions(self::itemFields($type), (array) ($entry['options'] ?? [])),
                'scope' => self::cleanScope($type, (array) ($entry['scope'] ?? [])),
            ], fn (mixed $v): bool => $v !== null && $v !== []);

            // ponytail: a value without settings of its own stays a plain id, as before.
            $rotate[] = $own === [] ? $id : ['source' => $id, ...$own];
        }

        $options = self::cleanOptions(self::fields($type), (array) ($widget['options'] ?? []));

        $scope = self::isDataType($type) ? self::cleanScope($type, (array) ($widget['scope'] ?? [])) : [];

        $title = is_string($widget['title'] ?? null) ? trim($widget['title']) : '';

        return [
            'id' => (string) $widget['id'],
            'type' => $type,
            'source' => $source,
            'rotate' => array_slice($rotate, 0, self::MAX_ROTATE),
            'x' => max(0, (int) ($widget['x'] ?? 0)),
            'y' => max(0, (int) ($widget['y'] ?? 0)),
            'w' => max(1, min($cols, (int) $widget['w'])),
            'h' => max(1, min($rows, (int) $widget['h'])),
            'title' => $title !== '' ? $title : null,
            'options' => $options,
            'scope' => $scope,
        ];
    }

    /**
     * A scope as stored, the widget's or a rotated value's: the keys its type takes, empty
     * values left out so the next level's (the widget's, then the screen's) apply.
     *
     * @param  array<mixed>  $given
     * @return array<string, mixed>
     */
    private static function cleanScope(string $type, array $given): array
    {
        $scope = [];
        $section = collect(self::sections($type))->firstWhere('id', 'scope');

        foreach (array_column($section['fields'] ?? [], 'key') as $key) {
            if (isset($given[$key]) && $given[$key] !== '') {
                $scope[$key] = $key === 'projects' ? array_values(array_map('strval', (array) $given[$key])) : (string) $given[$key];
            }
        }

        return $scope;
    }

    /**
     * Options as stored: the given fields only, typed, empty values left out.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<mixed>  $given
     * @return array<string, mixed>
     */
    private static function cleanOptions(array $fields, array $given): array
    {
        $options = [];

        foreach ($fields as $key => $field) {
            $value = $given[$key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $options[$key] = match ($field['type']) {
                'toggle' => (bool) $value,
                'number' => (float) $value,
                'checks' => array_values(array_unique(array_map('strval', (array) $value))),
                default => (string) $value,
            };
        }

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array{id: string, fields: list<array<string, mixed>>}
     */
    private static function section(string $id, array $fields): array
    {
        return ['id' => $id, 'fields' => $fields];
    }

    /**
     * @return array<string, list<array{id: string, fields: list<array<string, mixed>>}>>
     */
    private static function build(): array
    {
        $select = fn (string $key, array $options, string $default, bool $data = false, int $span = 1): array => ['key' => $key, 'type' => 'select', 'options' => $options, 'default' => $default, 'data' => $data, 'span' => $span];
        $toggle = fn (string $key, ?bool $default, bool $data = false): array => ['key' => $key, 'type' => 'toggle', 'default' => $default, 'data' => $data, 'span' => 2];
        $text = fn (string $key, int $span = 1, string $type = 'text'): array => ['key' => $key, 'type' => $type, 'default' => null, 'data' => false, 'span' => $span];
        $number = fn (string $key): array => ['key' => $key, 'type' => 'number', 'default' => null, 'data' => true, 'span' => 1];
        $section = self::section(...);

        $periods = array_map(fn (Period $p): string => $p->value, Period::cases());
        $scope = fn (bool $timed): array => $section('scope', array_values(array_filter([
            ['key' => 'projects', 'type' => 'projects', 'default' => null, 'data' => true, 'span' => 2],
            ['key' => 'environment', 'type' => 'environment', 'default' => null, 'data' => true, 'span' => 1],
            $timed ? ['key' => 'period', 'type' => 'select', 'options' => $periods, 'default' => null, 'data' => true, 'span' => 1] : null,
            $timed ? ['key' => 'release', 'type' => 'select', 'options' => array_map(fn (ReleaseScope $r): string => $r->value, ReleaseScope::cases()), 'default' => 'all', 'data' => true, 'span' => 1] : null,
            ['key' => 'filter', 'type' => 'text', 'default' => null, 'data' => true, 'span' => 2],
            // Only for a source that filters a route.
            ['key' => 'domain', 'type' => 'text', 'default' => null, 'data' => true, 'span' => 2],
        ])));
        $display = fn (bool $data): array => $section('display', array_values(array_filter([
            $toggle('show_title', true),
            $data ? $toggle('show_period', true) : null,
            $data ? $toggle('status_colors', true) : null,
            $data ? $toggle('ok_border', true) : null,
            $select('text_size', ['auto', 's', 'm', 'l', 'xl'], 'auto'),
            $select('title_size', ['s', 'm', 'l', 'xl'], 'm'),
            $select('align', ['left', 'center'], 'left'),
            $select('valign', ['top', 'middle'], 'top'),
            $data ? $select('refresh', ['live', '1m', '5m'], 'live') : null,
            $data ? $select('hide_when', ['never', 'ok', 'empty'], 'never') : null,
        ])));
        $rotation = $section('rotation', [
            $select('rotate_every', ['5', '10', '20', '30', '60'], '10'),
            $select('transition', ['fade', 'slide', 'flip', 'none'], 'fade'),
            $toggle('hold_problem', true),
            $toggle('progress', true),
            // How long the widget's own source shows; set on its row under Rotate through.
            ['key' => 'source_seconds', 'type' => 'number', 'default' => null, 'data' => false, 'span' => 1, 'min' => 1, 'max' => self::MAX_ROTATE_SECONDS],
        ]);
        $limits = [$number('amber'), $number('red'), $toggle('low_is_bad', null, true)];
        // Where an External JSON value reads from: the source, and a JSONPath to one number, or for a list to its items.
        $external = fn (bool $rows = false): array => array_map(fn (array $field): array => [...$field, 'data' => true, 'span' => 2, 'sources' => ['external_json']], [
            ['key' => 'data_source', 'type' => 'data_source', 'default' => null],
            $text('json_path'),
            ...($rows ? [$text('label_path'), $text('value_path')] : []),
        ]);

        return [
            'stat' => [
                $section('value', [
                    ...$external(),
                    ...$limits,
                    $select('compare', ['none', 'previous', 'yesterday', 'last_week'], 'previous', true, 2),
                    $select('decimals', ['auto', '0', '1', '2'], 'auto'),
                    $toggle('sparkline', true, true),
                    $text('prefix'),
                    $text('suffix'),
                ]),
                $rotation, $scope(true), $display(true),
            ],
            'gauge' => [
                $section('scale', [...$external(), $number('min'), $number('max'), ...$limits, $toggle('show_limits', true), $toggle('show_red_limit', true)]),
                $rotation, $scope(true), $display(true),
            ],
            'chart' => [
                $section('chart', [
                    ...$external(),
                    $select('style', ['bars', 'area', 'line'], 'bars'),
                    $toggle('smooth', false),
                    $select('split_by', ['none', 'route', 'queue', 'host', 'check', 'environment', 'release', 'project'], 'none', true),
                    $select('top', ['5', '10', '20'], '5', true),
                    $number('red'),
                    $toggle('overlay_previous', false, true),
                    $toggle('limit_line', true),
                    $toggle('y_zero', true),
                    $toggle('legend', false),
                    $toggle('axis', true),
                    $toggle('y_axis', true),
                    $toggle('grid', true),
                ]),
                $rotation, $scope(true), $display(true),
            ],
            'list' => [
                $section('rows', [
                    ...$external(true),
                    $select('limit', ['auto', '3', '5', '10', '20'], 'auto', true),
                    $select('sort', ['newest', 'worst', 'last_seen', 'most_frequent'], 'newest', true),
                    $toggle('only_problems', false, true),
                    $toggle('show_age', true),
                    $toggle('show_count', true),
                    $toggle('show_project', false),
                    $toggle('wrap', false),
                    $toggle('auto_scroll', true),
                ]),
                $rotation, $scope(true), $display(true),
            ],
            'health' => [
                $section('checks', [
                    $select('view', ['auto', 'summary', 'list', 'cards'], 'auto', false, 2),
                    ['key' => 'checks', 'type' => 'checks', 'default' => null, 'data' => true, 'span' => 2],
                    $toggle('only_problems', false, true),
                    $toggle('show_uptime', true),
                    $toggle('show_timeline', true),
                    $toggle('show_message', true),
                    $select('stale_as', ['stale', 'degraded', 'ok'], 'stale', false, 2),
                ]),
                $rotation, $scope(false), $display(true),
            ],
            'clock' => [
                $section('clock', [
                    $select('mode', ['clock', 'text', 'both'], 'clock'),
                    $select('format', ['24', '12'], '24'),
                    $text('timezone', 2, 'timezone'),
                    $toggle('seconds', false),
                    $toggle('date', false),
                    $text('text', 2),
                ]),
                $display(false),
            ],
            'empty' => [],
        ];
    }
}
