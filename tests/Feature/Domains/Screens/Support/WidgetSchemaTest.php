<?php

declare(strict_types=1);

use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Support\WidgetSchema;
use Illuminate\Support\Facades\Validator;

covers(WidgetSchema::class);

describe('the schema', function () {
    it('has sections for every type, scope only for data types', function (string $type) {
        $sections = array_column(WidgetSchema::sections($type), 'id');

        expect(in_array('scope', $sections, true))->toBe(WidgetSchema::isDataType($type))
            ->and(in_array('rotation', $sections, true))->toBe(in_array($type, WidgetSchema::ROTATING_TYPES, true));
    })->with(array_values(array_diff(WidgetSchema::types(), ['empty'])));

    it('knows every visualization and the layout widgets', function () {
        expect(WidgetSchema::types())->toBe([...array_map(fn (WidgetType $t): string => $t->value, WidgetType::cases()), 'clock', 'countdown', 'message', 'qr', 'media', 'empty'])
            ->and(WidgetSchema::sections('empty'))->toBe([])
            ->and(WidgetSchema::sections('pie'))->toBe([]);
    });

    it('labels every field and choice for the designer', function () {
        foreach (WidgetSchema::all() as $sections) {
            foreach ($sections as $section) {
                expect(__('ui/screens.sections.'.$section['id']))->not->toStartWith('ui/');

                foreach ($section['fields'] as $field) {
                    expect(__('ui/screens.fields.'.$field['key'].'.label'))->not->toStartWith('ui/');

                    foreach ($field['options'] ?? [] as $value) {
                        if ($field['key'] !== 'period') {
                            expect(__("ui/screens.fields.{$field['key']}.options.{$value}"))->not->toStartWith('ui/');
                        }
                    }
                }
            }
        }
    });

    it('fills in the defaults under what is stored', function () {
        expect(WidgetSchema::options('stat', ['compare' => 'none']))->toMatchArray(['compare' => 'none', 'sparkline' => true, 'decimals' => 'auto'])
            ->and(WidgetSchema::options('stat', []))->not->toHaveKey('low_is_bad');
    });
});

describe('validating a widget', function () {
    $validate = fn (array $widget, int $cols = 4, int $rows = 4): array => Validator::make(['w' => $widget], WidgetSchema::rules('w', $widget, $cols, $rows, ['p1']))->errors()->keys();

    it('accepts a complete widget of every type', function (array $widget) use ($validate) {
        expect($validate($widget))->toBe([]);
    })->with([
        'a stat' => [['id' => 'a', 'type' => 'stat', 'source' => 'p95', 'rotate' => ['apdex'], 'w' => 1, 'h' => 1, 'options' => ['amber' => 400, 'low_is_bad' => false, 'decimals' => '1', 'prefix' => '~'], 'scope' => ['projects' => ['p1'], 'period' => '1h', 'release' => 'current', 'filter' => 'GET /*', 'environment' => '*']]],
        'a gauge' => [['id' => 'b', 'type' => 'gauge', 'source' => 'host_cpu', 'w' => 1, 'h' => 1, 'options' => ['min' => 0, 'max' => 1, 'show_limits' => true]]],
        'a chart' => [['id' => 'c', 'type' => 'chart', 'source' => 'requests', 'w' => 4, 'h' => 2, 'options' => ['style' => 'area', 'split_by' => 'route', 'top' => '10']]],
        'a list' => [['id' => 'd', 'type' => 'list', 'source' => 'queues', 'w' => 2, 'h' => 2, 'options' => ['limit' => 'auto', 'only_problems' => true]]],
        'health' => [['id' => 'e', 'type' => 'health', 'source' => 'checks', 'w' => 2, 'h' => 1, 'options' => ['view' => 'cards', 'checks' => ['8a3a9c5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e']]]],
        'an external JSON list' => [['id' => 'h', 'type' => 'list', 'source' => 'external_json', 'w' => 2, 'h' => 2, 'options' => ['data_source' => '8a3a9c5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e', 'json_path' => "$.services[?(@.status=='down')]", 'label_path' => 'name', 'value_path' => 'ms']]],
        'a calendar' => [['id' => 'k', 'type' => 'list', 'source' => 'calendar', 'w' => 2, 'h' => 2, 'options' => ['data_source' => '8a3a9c5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e', 'days_before' => '1', 'days_after' => '30', 'upcoming_only' => false]]],
        'a project overview' => [['id' => 'n', 'type' => 'overview', 'source' => 'projects', 'w' => 4, 'h' => 2, 'options' => ['problems_first' => false, 'only_problems' => true, 'new_issues' => '5', 'stale_as' => 'ok'], 'scope' => ['period' => '24h']]],
        'an SLO' => [['id' => 'o', 'type' => 'slo', 'source' => 'latency', 'w' => 2, 'h' => 1, 'options' => ['slo_target' => 'custom', 'slo_custom_target' => 99.8, 'threshold_ms' => 300], 'scope' => ['period' => '30d', 'filter' => '/checkout*']]],
        'a clock' => [['id' => 'f', 'type' => 'clock', 'w' => 1, 'h' => 1, 'options' => ['timezone' => 'Europe/Copenhagen', 'format' => '12']]],
        'an empty cell' => [['id' => 'g', 'type' => 'empty', 'w' => 3, 'h' => 2]],
        'a camera' => [['id' => 'i', 'type' => 'media', 'w' => 2, 'h' => 2, 'options' => ['media' => 'image', 'media_url' => 'https://cam.example.com/snapshot.jpg?token='.str_repeat('a', 400), 'fit' => 'cover', 'reload_every' => '1']]],
        'a world clock' => [['id' => 'k', 'type' => 'clock', 'w' => 1, 'h' => 1, 'options' => ['zones' => "America/New_York\nAsia/Tokyo"]]],
        'a countdown' => [['id' => 'l', 'type' => 'countdown', 'w' => 2, 'h' => 1, 'options' => ['target' => '2026-12-01T10:00', 'countdown_label' => 'Release 2.0', 'after' => 'zero']]],
        'a ticker' => [['id' => 'm', 'type' => 'message', 'w' => 4, 'h' => 1, 'options' => ['message' => "Deploy freeze\nfrom Friday", 'tone' => 'warning', 'scroll' => true]]],
        'a slideshow' => [['id' => 'j', 'type' => 'media', 'w' => 2, 'h' => 2, 'options' => ['media_url' => "https://example.com/a.jpg\r\n\nhttps://example.com/b.png\n", 'slide_seconds' => '20']]],
    ]);

    it('refuses what the type does not take', function (array $widget, string $error) use ($validate) {
        expect($validate($widget))->toContain($error);
    })->with([
        'a check that is no uuid' => [['id' => 'e', 'type' => 'health', 'source' => 'checks', 'w' => 1, 'h' => 1, 'options' => ['checks' => ['x']]], 'w.options.checks.0'],
        'a data source that is no uuid' => [['id' => 'a', 'type' => 'stat', 'source' => 'external_json', 'w' => 1, 'h' => 1, 'options' => ['data_source' => 'x']], 'w.options.data_source'],
        'a media address that runs script' => [['id' => 'i', 'type' => 'media', 'w' => 1, 'h' => 1, 'options' => ['media' => 'page', 'media_url' => 'javascript:alert(1)']], 'w.options.media_url'],
        'a slideshow with one address that runs script' => [['id' => 'i', 'type' => 'media', 'w' => 1, 'h' => 1, 'options' => ['media_url' => "https://example.com/a.jpg\njavascript:alert(1)"]], 'w.options.media_url'],
        'more slides than a slideshow takes' => [['id' => 'i', 'type' => 'media', 'w' => 1, 'h' => 1, 'options' => ['media_url' => implode("\n", array_fill(0, WidgetSchema::MAX_SLIDES + 1, 'https://example.com/a.jpg'))]], 'w.options.media_url'],
        'a calendar over days the designer does not offer' => [['id' => 'k', 'type' => 'list', 'source' => 'calendar', 'w' => 2, 'h' => 2, 'options' => ['days_after' => '365']], 'w.options.days_after'],
        'a world clock with an unknown city' => [['id' => 'k', 'type' => 'clock', 'w' => 1, 'h' => 1, 'options' => ['zones' => "Asia/Tokyo\nMars/Olympus"]], 'w.options.zones'],
        'a countdown to no moment' => [['id' => 'l', 'type' => 'countdown', 'w' => 1, 'h' => 1, 'options' => ['target' => 'next friday']], 'w.options.target'],
        'a message of an unknown tone' => [['id' => 'm', 'type' => 'message', 'w' => 1, 'h' => 1, 'options' => ['tone' => 'panic']], 'w.options.tone'],
        'an unknown time zone' => [['id' => 'f', 'type' => 'clock', 'w' => 1, 'h' => 1, 'options' => ['timezone' => 'Nowhere']], 'w.options.timezone'],
        'an SLO target of every request' => [['id' => 'o', 'type' => 'slo', 'source' => 'availability', 'w' => 1, 'h' => 1, 'options' => ['slo_target' => 'custom', 'slo_custom_target' => 100]], 'w.options.slo_custom_target'],
        'an SLO target the designer does not offer' => [['id' => 'o', 'type' => 'slo', 'source' => 'availability', 'w' => 1, 'h' => 1, 'options' => ['slo_target' => '42']], 'w.options.slo_target'],
        'a gauge source on a stat' => [['id' => 'a', 'type' => 'stat', 'source' => 'health_uptime', 'w' => 1, 'h' => 1], 'w.source'],
        'a widget that is no object' => [[], 'w'],
        'a rotated value of another type' => [['id' => 'a', 'type' => 'stat', 'source' => 'p95', 'rotate' => [['source' => 'health_uptime']], 'w' => 1, 'h' => 1], 'w.rotate.0'],
        'a rotated value without a source' => [['id' => 'a', 'type' => 'stat', 'source' => 'p95', 'rotate' => [['seconds' => 5]], 'w' => 1, 'h' => 1], 'w.rotate.0'],
        'a rotated value shown for no time' => [['id' => 'a', 'type' => 'stat', 'source' => 'p95', 'rotate' => [['source' => 'apdex', 'seconds' => 0]], 'w' => 1, 'h' => 1], 'w.rotate.0.seconds'],
        'a rotated value with a limit that is no number' => [['id' => 'a', 'type' => 'stat', 'source' => 'p95', 'rotate' => [['source' => 'apdex', 'options' => ['red' => 'high']]], 'w' => 1, 'h' => 1], 'w.rotate.0.options.red'],
        'a rotated value of a project outside the team' => [['id' => 'a', 'type' => 'stat', 'source' => 'p95', 'rotate' => [['source' => 'apdex', 'scope' => ['projects' => ['p2']]]], 'w' => 1, 'h' => 1], 'w.rotate.0.scope.projects.0'],
        'a rotated value of an unknown release' => [['id' => 'a', 'type' => 'stat', 'source' => 'p95', 'rotate' => [['source' => 'apdex', 'scope' => ['release' => 'someday']]], 'w' => 1, 'h' => 1], 'w.rotate.0.scope.release'],
        'a rotated value over an unknown period' => [['id' => 'a', 'type' => 'stat', 'source' => 'p95', 'rotate' => [['source' => 'apdex', 'scope' => ['period' => '2y']]], 'w' => 1, 'h' => 1], 'w.rotate.0.scope.period'],
        'a rotated health value with a check that is no uuid' => [['id' => 'e', 'type' => 'health', 'source' => 'checks', 'rotate' => [['source' => 'checks', 'options' => ['checks' => ['x']]]], 'w' => 1, 'h' => 1], 'w.rotate.0.options.checks.0'],
    ]);

    it('takes rotated values as ids and as objects with settings of their own', function () use ($validate) {
        expect($validate(['id' => 'a', 'type' => 'stat', 'source' => 'p95', 'w' => 1, 'h' => 1, 'options' => ['source_seconds' => 20], 'rotate' => [
            'apdex',
            ['source' => 'requests', 'seconds' => 30, 'title' => 'Traffic', 'options' => ['amber' => 100, 'red' => 200, 'low_is_bad' => true, 'suffix' => ' rpm'], 'scope' => ['period' => '24h']],
            ['source' => 'queue_size', 'scope' => ['projects' => ['p1'], 'environment' => '*', 'release' => 'current', 'filter' => 'bulk']],
        ]]))->toBe([]);
    });

    it('takes health values that repeat its source with checks of their own', function () use ($validate) {
        expect($validate(['id' => 'e', 'type' => 'health', 'source' => 'checks', 'w' => 1, 'h' => 1, 'options' => ['checks' => ['8a3a9c5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e']], 'rotate' => [
            ['source' => 'checks', 'seconds' => 5, 'options' => ['checks' => ['9b4b0d6f-7a8b-4c9d-8e1f-2a3b4c5d6e7f'], 'show_uptime' => false]],
            'checks',
        ]]))->toBe([]);
    });
});

describe('storing a widget', function () {
    it('keeps only what its type takes, typed, and leaves empty values to the defaults', function () {
        $clean = WidgetSchema::clean([
            'id' => 'a', 'type' => 'stat', 'source' => 'p95', 'rotate' => ['p95', 'apdex', 'apdex'], 'x' => '2', 'y' => -1, 'w' => 9, 'h' => 0, 'title' => '  ',
            'options' => ['amber' => '400', 'sparkline' => 0, 'prefix' => '', 'style' => 'bars', 'decimals' => 1],
            'scope' => ['projects' => [], 'environment' => '', 'period' => '7d', 'nonsense' => 1],
            'extra' => 'dropped',
        ], 4, 3);

        expect($clean)->toBe([
            'id' => 'a', 'type' => 'stat', 'source' => 'p95', 'rotate' => ['apdex'], 'x' => 2, 'y' => 0, 'w' => 4, 'h' => 1, 'title' => null,
            'options' => ['amber' => 400.0, 'decimals' => '1', 'sparkline' => false],
            'scope' => ['projects' => [], 'period' => '7d'],
        ]);
    });

    it('keeps a rotated value\'s own settings that its type takes, and a plain id for one without', function () {
        $clean = WidgetSchema::clean([
            'id' => 'a', 'type' => 'stat', 'source' => 'p95', 'w' => 1, 'h' => 1, 'options' => ['source_seconds' => '20'],
            'rotate' => [
                'apdex',
                ['source' => 'requests', 'seconds' => '30', 'title' => ' Traffic ', 'options' => ['red' => '200', 'show_title' => false, 'rotate_every' => '5'], 'scope' => ['period' => '24h', 'projects' => ['x'], 'filter' => 'GET /*', 'environment' => '', 'nonsense' => 1], 'extra' => 1],
                ['source' => 'users_affected', 'seconds' => '', 'title' => '', 'options' => [], 'scope' => []],
                ['source' => 'apdex', 'seconds' => 5],
                ['source' => 'p95', 'seconds' => 5],
            ],
        ], 4, 4);

        expect($clean['rotate'])->toBe([
            'apdex',
            ['source' => 'requests', 'seconds' => 30, 'title' => 'Traffic', 'options' => ['red' => 200.0], 'scope' => ['projects' => ['x'], 'period' => '24h', 'filter' => 'GET /*']],
            'users_affected',
        ])->and($clean['options'])->toBe(['source_seconds' => 20.0])
            ->and(WidgetSchema::rotation($clean))->toBe([
                ['source' => 'p95', 'seconds' => 20, 'title' => null, 'options' => [], 'scope' => []],
                ['source' => 'apdex', 'seconds' => null, 'title' => null, 'options' => [], 'scope' => []],
                ['source' => 'requests', 'seconds' => 30, 'title' => 'Traffic', 'options' => ['red' => 200.0], 'scope' => ['projects' => ['x'], 'period' => '24h', 'filter' => 'GET /*']],
                ['source' => 'users_affected', 'seconds' => null, 'title' => null, 'options' => [], 'scope' => []],
            ]);
    });

    it('keeps health values that repeat its source, each with its own checks and seconds', function () {
        $clean = WidgetSchema::clean([
            'id' => 'h', 'type' => 'health', 'source' => 'checks', 'w' => 1, 'h' => 1, 'options' => ['checks' => ['u1'], 'source_seconds' => 5, 'show_uptime' => false],
            'rotate' => [
                ['source' => 'checks', 'seconds' => 5, 'options' => ['checks' => ['u2'], 'red' => 1]],
                ['source' => 'checks', 'seconds' => 5, 'options' => ['checks' => ['u3', 'u3']]],
                ['source' => 'checks', 'title' => 'Everything'],
            ],
        ], 4, 4);

        expect($clean['rotate'])->toBe([
            ['source' => 'checks', 'seconds' => 5, 'options' => ['checks' => ['u2']]],
            ['source' => 'checks', 'seconds' => 5, 'options' => ['checks' => ['u3']]],
            ['source' => 'checks', 'title' => 'Everything'],
        ])->and(WidgetSchema::rotation($clean))->toBe([
            ['source' => 'checks', 'seconds' => 5, 'title' => null, 'options' => [], 'scope' => []],
            ['source' => 'checks', 'seconds' => 5, 'title' => null, 'options' => ['checks' => ['u2']], 'scope' => []],
            ['source' => 'checks', 'seconds' => 5, 'title' => null, 'options' => ['checks' => ['u3']], 'scope' => []],
            // None picked shows every check, not the widget's.
            ['source' => 'checks', 'seconds' => null, 'title' => 'Everything', 'options' => ['checks' => []], 'scope' => []],
        ]);
    });

    it('gives layout widgets no source, rotation or scope', function () {
        $clean = WidgetSchema::clean(['id' => 'c', 'type' => 'clock', 'source' => 'requests', 'rotate' => ['x'], 'w' => 1, 'h' => 1, 'title' => 'Ops', 'scope' => ['period' => '1h'], 'options' => ['checks' => ['a']]], 4, 4);

        expect($clean)->toMatchArray(['source' => null, 'rotate' => [], 'scope' => [], 'options' => [], 'title' => 'Ops']);
    });

    it('drops the period and release health has no use for', function () {
        $clean = WidgetSchema::clean(['id' => 'h', 'type' => 'health', 'source' => 'checks', 'w' => 1, 'h' => 1, 'options' => ['checks' => ['u1', 'u1']], 'scope' => ['period' => '1h', 'release' => 'current', 'environment' => 'production']], 4, 4);

        expect($clean['scope'])->toBe(['environment' => 'production'])->and($clean['options'])->toBe(['checks' => ['u1']]);
    });
});
