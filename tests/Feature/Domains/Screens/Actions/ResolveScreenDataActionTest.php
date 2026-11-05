<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Actions\ResolveScreenDataAction;
use App\Domains\Screens\Actions\ResolveWidgetDataAction;
use App\Domains\Screens\Data\WidgetScope;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Models\Screen;
use App\Domains\Shared\Enums\Period;
use App\Domains\Teams\Models\Team;

covers(ResolveScreenDataAction::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->screen = Screen::factory()->for($this->project->team)->create([
        'scope' => ['projects' => [$this->project->uuid], 'environment' => 'production', 'period' => '7d'],
        'widgets' => [
            ['id' => 'a', 'type' => 'stat', 'source' => 'requests', 'rotate' => ['p95'], 'w' => 1, 'h' => 1, 'title' => null, 'options' => ['compare' => 'none', 'prefix' => '~'], 'scope' => []],
            ['id' => 'b', 'type' => 'list', 'source' => 'latest_issues', 'rotate' => [], 'w' => 2, 'h' => 2, 'title' => null, 'options' => [], 'scope' => ['projects' => [], 'environment' => '*', 'period' => '1h']],
            ['id' => 'c', 'type' => 'empty', 'source' => null, 'rotate' => [], 'w' => 1, 'h' => 1, 'title' => null, 'options' => [], 'scope' => []],
        ],
    ]);
});

describe('a screen\'s data', function () {
    it('takes each widget\'s own scope over the screen\'s', function () {
        $action = app(ResolveScreenDataAction::class);

        $default = $action->scope($this->screen, $this->screen->widgets[0]);
        $own = $action->scope($this->screen, $this->screen->widgets[1]);

        expect($default->projects)->toBe([$this->project->uuid])
            ->and($default->environment)->toBe('production')
            ->and($default->period)->toBe(Period::Week)
            ->and($own->projects)->toBe([])
            ->and($own->environment)->toBeNull()
            ->and($own->period)->toBe(Period::Hour);
    });

    it('asks once per source with the options the data depends on, and caches the answer', function () {
        $calls = [];
        $this->mock(ResolveWidgetDataAction::class)->shouldReceive('execute')
            ->andReturnUsing(function (Team $team, WidgetType $type, string $source, WidgetScope $scope, array $options) use (&$calls): array {
                $calls[] = [$type->value, $source, $options];

                return ['source' => $source];
            });
        $action = app(ResolveScreenDataAction::class);

        $data = $action->execute($this->screen);
        $action->execute($this->screen);

        expect(array_keys($data))->toBe(['a', 'b'])
            ->and(array_keys($data['a']))->toBe(['requests', 'p95'])
            ->and($calls)->toHaveCount(3)
            ->and($calls[0][2])->toBe(['compare' => 'none', 'sparkline' => true])
            ->and($calls[2][2])->toBe(['limit' => 20, 'only_problems' => false, 'sort' => 'newest']);
    });

    it('gives a calendar its own options and the screen\'s time zone, else the server\'s', function (?string $timezone, string $expected) {
        $options = null;
        $this->mock(ResolveWidgetDataAction::class)->shouldReceive('execute')
            ->andReturnUsing(function (Team $team, WidgetType $type, string $source, WidgetScope $scope, array $given) use (&$options): array {
                $options = $given;

                return [];
            });
        config(['app.timezone' => 'UTC']);
        $this->screen->update(['timezone' => $timezone, 'widgets' => [
            ['id' => 'a', 'type' => 'list', 'source' => 'calendar', 'rotate' => [], 'w' => 2, 'h' => 2, 'title' => null, 'options' => ['data_source' => '8a3a9c5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e', 'days_after' => '30'], 'scope' => []],
        ]]);

        app(ResolveScreenDataAction::class)->execute($this->screen);

        expect($options)->toMatchArray(['data_source' => '8a3a9c5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e', 'days_before' => '0', 'days_after' => '30', 'upcoming_only' => true, 'timezone' => $expected])
            ->and($options)->not->toHaveKey('json_path');
    })->with([
        'the screen\'s' => ['America/Los_Angeles', 'America/Los_Angeles'],
        'none set' => [null, 'UTC'],
    ]);

    it('resolves a rotated value with its own limits and period over the widget\'s', function () {
        $this->screen->update(['widgets' => [
            ['id' => 'a', 'type' => 'stat', 'source' => 'requests', 'w' => 1, 'h' => 1, 'title' => null, 'options' => ['compare' => 'none', 'red' => 50.0], 'scope' => ['period' => '1h'], 'rotate' => [
                ['source' => 'p95', 'seconds' => 30, 'options' => ['red' => 900.0, 'low_is_bad' => true], 'scope' => ['period' => '24h']],
                'apdex',
            ]],
        ]]);
        $calls = [];
        $this->mock(ResolveWidgetDataAction::class)->shouldReceive('execute')
            ->andReturnUsing(function (Team $team, WidgetType $type, string $source, WidgetScope $scope, array $options) use (&$calls): array {
                $calls[$source] = [$scope->period, $options];

                return [];
            });

        app(ResolveScreenDataAction::class)->execute($this->screen);

        expect(array_keys($calls))->toBe(['requests', 'p95', 'apdex'])
            ->and($calls['requests'])->toEqual([Period::Hour, ['compare' => 'none', 'red' => 50.0, 'sparkline' => true]])
            ->and($calls['p95'])->toEqual([Period::Day, ['compare' => 'none', 'low_is_bad' => true, 'red' => 900.0, 'sparkline' => true]])
            ->and($calls['apdex'])->toEqual([Period::Hour, ['compare' => 'none', 'red' => 50.0, 'sparkline' => true]]);
    });

    it('scopes each rotated value by its own scope over the widget\'s over the screen\'s', function () {
        $this->screen->update(['widgets' => [
            ['id' => 'a', 'type' => 'stat', 'source' => 'requests', 'w' => 1, 'h' => 1, 'title' => null, 'options' => [], 'scope' => ['filter' => 'GET /checkout*', 'environment' => 'staging'], 'rotate' => [
                ['source' => 'queue_size', 'scope' => ['filter' => 'bulk', 'projects' => []]],
            ]],
        ]]);
        $scopes = [];
        $this->mock(ResolveWidgetDataAction::class)->shouldReceive('execute')
            ->andReturnUsing(function (Team $team, WidgetType $type, string $source, WidgetScope $scope) use (&$scopes): array {
                $scopes[$source] = [$scope->filter, $scope->environment, $scope->projects, $scope->period];

                return [];
            });

        app(ResolveScreenDataAction::class)->execute($this->screen);

        expect($scopes['requests'])->toBe(['GET /checkout*', 'staging', [$this->project->uuid], Period::Week])
            ->and($scopes['queue_size'])->toBe(['bulk', 'staging', [], Period::Week]);
    });

    it('keeps the widget\'s filter from a value that filters something else', function () {
        $this->screen->update(['widgets' => [
            ['id' => 'a', 'type' => 'stat', 'source' => 'requests', 'w' => 1, 'h' => 1, 'title' => null, 'options' => [], 'scope' => ['filter' => 'GET /checkout*'], 'rotate' => ['queue_size', 'p95']],
        ]]);
        $filters = [];
        $this->mock(ResolveWidgetDataAction::class)->shouldReceive('execute')
            ->andReturnUsing(function (Team $team, WidgetType $type, string $source, WidgetScope $scope) use (&$filters): array {
                $filters[$source] = $scope->filter;

                return [];
            });

        app(ResolveScreenDataAction::class)->execute($this->screen);

        expect($filters)->toBe(['requests' => 'GET /checkout*', 'queue_size' => null, 'p95' => 'GET /checkout*']);
    });

    it('resolves each health value with its own checks, under source#index past the first', function () {
        $this->screen->update(['widgets' => [
            ['id' => 'h', 'type' => 'health', 'source' => 'checks', 'w' => 1, 'h' => 1, 'title' => null, 'options' => ['checks' => ['u1'], 'only_problems' => true], 'scope' => [], 'rotate' => [
                ['source' => 'checks', 'options' => ['checks' => ['u2']]],
                'checks',
            ]],
        ]]);
        $calls = [];
        $this->mock(ResolveWidgetDataAction::class)->shouldReceive('execute')
            ->andReturnUsing(function (Team $team, WidgetType $type, string $source, WidgetScope $scope, array $options) use (&$calls): array {
                $calls[] = [$source, $options];

                return ['checks' => $options['checks'] ?? null];
            });

        $data = app(ResolveScreenDataAction::class)->execute($this->screen);

        expect($data['h'])->toBe(['checks' => ['checks' => ['u1']], 'checks#1' => ['checks' => ['u2']], 'checks#2' => ['checks' => []]])
            ->and($calls)->toBe([
                ['checks', ['checks' => ['u1'], 'only_problems' => true]],
                ['checks', ['checks' => ['u2'], 'only_problems' => true]],
                ['checks', ['checks' => [], 'only_problems' => true]],
            ]);
    });

    it('answers one widget alone', function () {
        expect(array_keys(app(ResolveScreenDataAction::class)->execute($this->screen, 'b')))->toBe(['b']);
    });

    it('reports a widget that fails and still answers the rest', function () {
        $this->mock(ResolveWidgetDataAction::class)->shouldReceive('execute')
            ->andReturnUsing(fn (Team $team, WidgetType $type, string $source): array => $source === 'p95' ? throw new RuntimeException('boom') : ['ok' => true]);

        $data = app(ResolveScreenDataAction::class)->execute($this->screen);

        expect($data['a'])->toBe(['requests' => ['ok' => true], 'p95' => null])
            ->and($data['b']['latest_issues'])->toBe(['ok' => true]);
    });
});

describe('a QR code widget', function () {
    it('comes with its picture, nothing while empty, and fails alone on a link that is not one', function (array $options, ?bool $drawn) {
        $screen = Screen::factory()->withQrWidget($options)->create();

        $qr = app(ResolveScreenDataAction::class)->execute($screen, 'qr')['qr']['qr'];

        match ($drawn) {
            true => expect(strtolower($qr['svg']))->toStartWith('<svg')->toContain('#f0a93b')->not->toContain('#161a1e'),
            false => expect($qr)->toBe(['svg' => null]),
            null => expect($qr)->toBeNull(),
        };
    })->with([
        'a wifi network' => [['content' => 'wifi', 'ssid' => 'Guests', 'password' => 'welcome', 'security' => 'WPA'], true],
        'a link' => [['url' => 'https://lumberjack.test'], true],
        'nothing yet' => [['content' => 'text'], false],
        'not a link' => [['url' => 'not a link'], null],
    ]);
});
