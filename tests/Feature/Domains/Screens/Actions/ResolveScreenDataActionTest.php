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
