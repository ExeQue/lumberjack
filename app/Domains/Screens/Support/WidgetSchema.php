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
 * (health check uuids). A field marked data is read by ResolveWidgetDataAction, so a
 * change to it refetches the widget; the rest only change how it draws. Scope fields are
 * stored in the widget's scope, not its options; absent means the screen's default.
 */
final class WidgetSchema
{
    /** Widgets drawn by the client alone, without a source. */
    public const array LAYOUT_TYPES = ['clock', 'empty'];

    /** Types that can rotate through several sources on one tile. */
    public const array ROTATING_TYPES = ['stat', 'gauge', 'chart', 'list'];

    public const int MAX_WIDGETS = 48;

    public const int MAX_ROTATE = 12;

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

        $rules = [
            "{$prefix}" => ['required', 'array'],
            "{$prefix}.id" => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'],
            "{$prefix}.type" => ['required', 'string', Rule::in(self::types())],
            "{$prefix}.source" => $data !== null ? ['required', 'string', $source] : ['nullable'],
            "{$prefix}.rotate" => ['nullable', 'array', 'max:'.self::MAX_ROTATE],
            "{$prefix}.rotate.*" => ['string', $source],
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
        ];

        foreach (self::fields($type) as $key => $field) {
            $attribute = "{$prefix}.options.{$key}";

            $rules[$attribute] = match ($field['type']) {
                'select' => ['nullable', Rule::in($field['options'])],
                'toggle' => ['nullable', 'boolean'],
                'number' => ['nullable', 'numeric'],
                'checks' => ['nullable', 'array', 'max:50'],
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
     * @return array{id: string, type: string, source: string|null, rotate: list<string>, x: int, y: int, w: int, h: int, title: string|null, options: array<string, mixed>, scope: array<string, mixed>}
     */
    public static function clean(array $widget, int $cols, int $rows): array
    {
        $type = (string) $widget['type'];
        $source = self::isDataType($type) ? (string) $widget['source'] : null;
        $rotate = in_array($type, self::ROTATING_TYPES, true)
            ? array_values(array_unique(array_filter(array_map('strval', (array) ($widget['rotate'] ?? [])), fn (string $s): bool => $s !== $source)))
            : [];
        $options = [];
        $given = (array) ($widget['options'] ?? []);

        foreach (self::fields($type) as $key => $field) {
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

        $scope = [];

        if (self::isDataType($type)) {
            $given = (array) ($widget['scope'] ?? []);

            $section = collect(self::sections($type))->firstWhere('id', 'scope');

            foreach (array_column($section['fields'] ?? [], 'key') as $key) {
                if (isset($given[$key]) && $given[$key] !== '') {
                    $scope[$key] = $key === 'projects' ? array_values(array_map('strval', (array) $given[$key])) : (string) $given[$key];
                }
            }
        }

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
        ])));
        $display = fn (bool $data): array => $section('display', array_values(array_filter([
            $toggle('show_title', true),
            $data ? $toggle('show_period', true) : null,
            $data ? $toggle('status_colors', true) : null,
            $select('text_size', ['auto', 's', 'm', 'l', 'xl'], 'auto'),
            $select('align', ['left', 'center'], 'left'),
            $select('valign', ['top', 'middle'], 'top'),
            $data ? $select('refresh', ['live', '1m', '5m'], 'live') : null,
            $data ? $select('hide_when', ['never', 'ok', 'empty'], 'never') : null,
        ])));
        $rotation = $section('rotation', [
            $select('rotate_every', ['5', '10', '20', '30', '60'], '10'),
            $select('title_size', ['s', 'm', 'l', 'xl'], 'm'),
            $select('transition', ['fade', 'slide', 'none'], 'fade'),
            $toggle('hold_problem', true),
            $toggle('dots', true),
        ]);
        $limits = [$number('amber'), $number('red'), $toggle('low_is_bad', null, true)];

        return [
            'stat' => [
                $section('value', [
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
                $section('scale', [$number('min'), $number('max'), ...$limits, $toggle('show_limits', true)]),
                $rotation, $scope(true), $display(true),
            ],
            'chart' => [
                $section('chart', [
                    $select('style', ['bars', 'area', 'line'], 'bars'),
                    $select('split_by', ['none', 'route', 'queue', 'host', 'environment', 'release', 'project'], 'none', true),
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
                    $select('limit', ['auto', '3', '5', '10', '20'], 'auto', true),
                    $select('sort', ['newest', 'worst', 'last_seen', 'most_frequent'], 'newest', true),
                    $toggle('only_problems', false, true),
                    $toggle('show_age', true),
                    $toggle('show_count', true),
                    $toggle('show_project', false),
                    $toggle('wrap', false),
                ]),
                $rotation, $scope(true), $display(true),
            ],
            'health' => [
                $section('checks', [
                    $select('view', ['auto', 'summary', 'list', 'cards'], 'auto', false, 2),
                    ['key' => 'checks', 'type' => 'checks', 'default' => null, 'data' => true, 'span' => 2],
                    $toggle('auto_scroll', true),
                    $toggle('only_problems', false, true),
                    $toggle('show_uptime', true),
                    $toggle('show_timeline', true),
                    $toggle('show_message', true),
                    $select('stale_as', ['stale', 'degraded', 'ok'], 'stale', false, 2),
                ]),
                $scope(false), $display(true),
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
