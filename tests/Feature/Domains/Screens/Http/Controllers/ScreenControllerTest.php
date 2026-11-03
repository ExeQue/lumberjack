<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Actions\CountScreenViewersAction;
use App\Domains\Screens\Actions\CreateScreenAction;
use App\Domains\Screens\Actions\DeleteScreenAction;
use App\Domains\Screens\Actions\DuplicateScreenAction;
use App\Domains\Screens\Actions\UpdateScreenAction;
use App\Domains\Screens\Actions\UpdateScreenLayoutAction;
use App\Domains\Screens\Actions\UpdateScreenWidgetAction;
use App\Domains\Screens\Enums\ScreenTemplate;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Events\ScreenChanged;
use App\Domains\Screens\Exceptions\OverlappingWidgetsException;
use App\Domains\Screens\Http\Controllers\ScreenController;
use App\Domains\Screens\Http\Requests\StoreScreenRequest;
use App\Domains\Screens\Http\Requests\UpdateScreenLayoutRequest;
use App\Domains\Screens\Http\Requests\UpdateScreenRequest;
use App\Domains\Screens\Http\Requests\UpdateScreenWidgetRequest;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Policies\ScreenPolicy;
use App\Domains\Screens\Presenters\ScreenPresenter;
use App\Domains\Screens\Support\ScreenLayout;
use App\Domains\Screens\Support\WidgetSources;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Teams\CreatesTeamMembers;

covers(
    ScreenController::class,
    ScreenPolicy::class,
    ScreenPresenter::class,
    ScreenChanged::class,
    CreateScreenAction::class,
    UpdateScreenAction::class,
    UpdateScreenLayoutAction::class,
    UpdateScreenWidgetAction::class,
    DeleteScreenAction::class,
    DuplicateScreenAction::class,
    CountScreenViewersAction::class,
    UpdateScreenRequest::class,
    UpdateScreenLayoutRequest::class,
    UpdateScreenWidgetRequest::class,
    StoreScreenRequest::class,
    ScreenTemplate::class,
    ScreenLayout::class,
    OverlappingWidgetsException::class,
);

uses(CreatesTeamMembers::class);

beforeEach(function () {
    ['user' => $this->user, 'project' => $this->project] = $this->memberWithProject(TeamRole::Member);
    $this->screen = Screen::factory()->for($this->project->team)->create();
    $this->widget = fn (array $fields = []): array => [
        'id' => 'w1', 'type' => 'stat', 'source' => 'requests', 'rotate' => [], 'w' => 1, 'h' => 1,
        'title' => null, 'options' => [], 'scope' => [], ...$fields,
    ];
});

describe('the screens of a team', function () {
    it('lists them for every member', function () {
        Screen::factory()->create();

        $this->actingAs($this->user)->get(route('screens.index'))->assertInertia(fn (Assert $page) => $page
            ->component('screens/index')
            ->has('screens', 1)
            ->where('screens.0.uuid', $this->screen->uuid)
            ->where('screens.0.widgets', 3));
    });

    it('creates one and opens it in the designer', function () {
        $response = $this->actingAs($this->user)->post(route('screens.store'), ['name' => 'Checkout']);

        $screen = Screen::query()->where('name', 'Checkout')->sole();
        $response->assertRedirect(route('screens.edit', $screen));
        expect($screen->team_id)->toBe($this->project->team_id)
            ->and($screen->cols)->toBe(4)
            ->and($screen->theme)->toBe(ScreenTheme::Dark)
            ->and($screen->widgets)->toBe([]);
    });

    it('starts from a template that fills its grid with widgets the registry knows', function (ScreenTemplate $template) {
        $this->actingAs($this->user)->post(route('screens.store'), ['name' => 'From '.$template->value, 'template' => $template->value])->assertSessionHasNoErrors();

        $screen = Screen::query()->where('name', 'From '.$template->value)->sole();
        [$cols, $rows] = $template->grid();
        $cells = array_sum(array_map(fn (array $w): int => $w['w'] * $w['h'], $screen->widgets));

        expect($screen->cols)->toBe($cols)
            ->and($screen->rows)->toBe($rows)
            ->and($cells)->toBeLessThanOrEqual($cols * $rows)
            ->and(collect($screen->widgets)->pluck('id')->duplicates())->toBeEmpty();

        foreach ($screen->widgets as $widget) {
            expect(WidgetSources::has(WidgetType::from($widget['type']), $widget['source']))->toBeTrue();
        }
    })->with(array_filter(ScreenTemplate::cases(), fn (ScreenTemplate $t): bool => $t !== ScreenTemplate::Blank));

    it('offers every template on the list page', function () {
        $this->actingAs($this->user)->get(route('screens.index'))
            ->assertInertia(fn (Assert $page) => $page->has('templates', count(ScreenTemplate::cases()))
                ->where('templates.1.id', 'overview')
                ->has('templates.1.widgets.0.w'));
    });

    it('refuses an unknown template', function () {
        $this->actingAs($this->user)->post(route('screens.store'), ['name' => 'X', 'template' => 'nope'])->assertSessionHasErrors('template');
    });

    it('refuses a screen without a name', function () {
        $this->actingAs($this->user)->post(route('screens.store'), ['name' => ''])->assertSessionHasErrors('name');
    });

    it('renames, duplicates and deletes one, telling open kiosks', function () {
        Event::fake([ScreenChanged::class]);

        $this->actingAs($this->user)->put(route('screens.update', $this->screen), ['name' => 'Wall'])->assertRedirect();
        $this->actingAs($this->user)->post(route('screens.duplicate', $this->screen))->assertSessionHas('status');
        $copy = Screen::query()->where('name', 'Wall (copy)')->sole();
        $this->actingAs($this->user)->delete(route('screens.destroy', $this->screen))->assertRedirect(route('screens.index'));

        expect($copy->uuid)->not->toBe($this->screen->uuid)
            ->and($copy->widgets)->toBe($this->screen->widgets)
            ->and(Screen::query()->whereKey($this->screen->id)->exists())->toBeFalse();
        Event::assertDispatchedTimes(ScreenChanged::class, 2);
        Event::assertDispatched(ScreenChanged::class, fn (ScreenChanged $e): bool => $e->broadcastAs() === 'screen.deleted');
    });

    it('keeps other teams out', function (string $method, string $route, array $body) {
        $outsider = User::factory()->inTeam()->create();

        $this->actingAs($outsider)->json($method, route($route, $this->screen), $body)->assertForbidden();
    })->with([
        'the kiosk' => ['get', 'screens.show', []],
        'the designer' => ['get', 'screens.edit', []],
        'the data' => ['get', 'screens.data', []],
        'the viewer count' => ['get', 'screens.viewers', []],
        'renaming' => ['put', 'screens.update', ['name' => 'Mine']],
        'the layout' => ['put', 'screens.layout', ['cols' => 4, 'rows' => 4, 'widgets' => []]],
        'duplicating' => ['post', 'screens.duplicate', []],
        'deleting' => ['delete', 'screens.destroy', []],
    ]);

    it('answers an unknown screen with 404', function () {
        $this->actingAs($this->user)->get('/screens/'.fake()->uuid())->assertNotFound();
    });
});

describe('the pages', function () {
    it('shows the kiosk with what it needs to draw and listen', function () {
        $this->actingAs($this->user)->get(route('screens.show', $this->screen))->assertInertia(fn (Assert $page) => $page
            ->component('screens/kiosk')
            ->where('screen.uuid', $this->screen->uuid)
            ->where('screen.current_theme', 'dark')
            ->where('channel', 'screen.'.$this->screen->uuid)
            ->where('data_url', '/screens/'.$this->screen->uuid.'/data')
            ->has('schema.stat')
            ->has('sources.list')
            ->where('projects.0.uuid', $this->project->uuid));
    });

    it('gives the designer the schema, sources, environments and checks', function () {
        $this->actingAs($this->user)->get(route('screens.edit', $this->screen))->assertInertia(fn (Assert $page) => $page
            ->component('screens/edit')
            ->has('schema.health')
            ->has('sources.stat', 14)
            ->has('environments')
            ->has('checks')
            ->where('limits.max_cols', Screen::MAX_COLS));
    });
});

describe('saving from the designer', function () {
    beforeEach(fn () => Event::fake([ScreenChanged::class]));

    it('saves the layout in order and tells open kiosks', function () {
        $this->actingAs($this->user)->putJson(route('screens.layout', $this->screen), [
            'cols' => 6,
            'rows' => 3,
            'widgets' => [
                ($this->widget)(['id' => 'b', 'w' => 2, 'options' => ['amber' => '5', 'compare' => 'none', 'unknown' => 'x']]),
                ($this->widget)(['id' => 'a', 'type' => 'clock', 'source' => 'ignored', 'options' => ['mode' => 'both', 'text' => 'Ops']]),
            ],
        ])->assertOk()->assertJsonStructure(['updated_at']);

        $screen = $this->screen->refresh();
        expect($screen->cols)->toBe(6)
            ->and(array_column($screen->widgets, 'id'))->toBe(['b', 'a'])
            ->and($screen->widgets[0]['options'])->toEqual(['amber' => 5.0, 'compare' => 'none'])
            ->and($screen->widgets[1]['source'])->toBeNull();
        Event::assertDispatched(ScreenChanged::class, fn (ScreenChanged $e): bool => $e->broadcastAs() === 'screen.changed'
            && $e->broadcastOn()[0]->name === 'private-screen.'.$this->screen->uuid
            && count($e->broadcastOn()) === 1);
    });

    it('does not tell anyone when nothing changed', function () {
        $this->actingAs($this->user)->putJson(route('screens.layout', $this->screen), [
            'cols' => 4, 'rows' => 4, 'widgets' => $this->screen->widgets,
        ])->assertOk();

        Event::assertNotDispatched(ScreenChanged::class);
    });

    it('refuses widgets the schema does not allow', function (array $widget, string $error) {
        $this->actingAs($this->user)->putJson(route('screens.layout', $this->screen), [
            'cols' => 4, 'rows' => 4, 'widgets' => [($this->widget)($widget)],
        ])->assertUnprocessable()->assertJsonValidationErrors($error);
    })->with([
        'an unknown type' => [['type' => 'pie'], 'widgets.0.type'],
        'a source of another type' => [['source' => 'latest_issues'], 'widgets.0.source'],
        'a data widget without a source' => [['source' => null], 'widgets.0.source'],
        'rotating through another type' => [['rotate' => ['hosts']], 'widgets.0.rotate.0'],
        'wider than the grid' => [['w' => 5], 'widgets.0.w'],
        'taller than the grid' => [['h' => 5], 'widgets.0.h'],
        'a choice the select does not offer' => [['options' => ['compare' => 'tomorrow']], 'widgets.0.options.compare'],
        'a toggle that is not one' => [['options' => ['sparkline' => 'maybe']], 'widgets.0.options.sparkline'],
        'a limit that is not a number' => [['options' => ['red' => 'high']], 'widgets.0.options.red'],
        'a project of another team' => [['scope' => ['projects' => ['not-ours']]], 'widgets.0.scope.projects.0'],
        'an unknown period' => [['scope' => ['period' => '2y']], 'widgets.0.scope.period'],
        'a malformed id' => [['id' => 'no spaces'], 'widgets.0.id'],
    ]);

    it('refuses two widgets with one id and a grid out of range', function () {
        $this->actingAs($this->user)->putJson(route('screens.layout', $this->screen), [
            'cols' => 13, 'rows' => 1, 'widgets' => [($this->widget)(), ($this->widget)()],
        ])->assertUnprocessable()->assertJsonValidationErrors(['cols', 'rows', 'widgets.1.id']);
    });

    it('saves one widget in its place', function () {
        $this->actingAs($this->user)->putJson(route('screens.widget', [$this->screen, 'traffic']), [
            'widget' => ($this->widget)(['id' => 'elsewhere', 'type' => 'chart', 'source' => 'p95', 'w' => 2, 'h' => 2, 'title' => '  Latency  ', 'rotate' => ['p95', 'apdex']]),
        ])->assertOk();

        $widget = $this->screen->refresh()->widgets[1];
        expect($widget)->toMatchArray(['id' => 'traffic', 'source' => 'p95', 'title' => 'Latency', 'rotate' => ['apdex']]);
        Event::assertDispatched(ScreenChanged::class);
    });

    it('answers 404 for a widget the screen does not have', function () {
        $this->actingAs($this->user)->putJson(route('screens.widget', [$this->screen, 'nope']), ['widget' => ($this->widget)()])->assertNotFound();
    });

    it('saves the theme, its schedule and the default scope', function () {
        $this->actingAs($this->user)->putJson(route('screens.update', $this->screen), [
            'theme' => 'schedule', 'light_from' => '06:30', 'light_to' => '20:00', 'timezone' => 'America/New_York',
            'scope' => ['projects' => [$this->project->uuid], 'environment' => 'production', 'period' => '7d'],
        ])->assertOk();

        $screen = $this->screen->refresh();
        expect($screen->theme)->toBe(ScreenTheme::Schedule)
            ->and($screen->light_from)->toBe('06:30')
            ->and($screen->timezone)->toBe('America/New_York')
            ->and($screen->scope)->toBe(['projects' => [$this->project->uuid], 'environment' => 'production', 'period' => '7d']);
    });

    it('refuses a time that is not one and a zone that does not exist', function () {
        $this->actingAs($this->user)->putJson(route('screens.update', $this->screen), [
            'light_from' => '25:00', 'timezone' => 'Mars/Olympus', 'theme' => 'sepia',
        ])->assertJsonValidationErrors(['light_from', 'timezone', 'theme']);
    });
});

describe('the data endpoint', function () {
    it('resolves every widget, each by its sources, and one widget alone', function () {
        Issue::factory()->for($this->project)->count(2)->create();
        $this->screen->update(['widgets' => [
            ($this->widget)(['id' => 'issues', 'source' => 'open_issues', 'rotate' => ['requests']]),
            ($this->widget)(['id' => 'list', 'type' => 'list', 'source' => 'latest_issues']),
            ($this->widget)(['id' => 'gauge', 'type' => 'gauge', 'source' => 'error_rate']),
            ($this->widget)(['id' => 'chart', 'type' => 'chart', 'source' => 'requests']),
            ($this->widget)(['id' => 'health', 'type' => 'health', 'source' => 'checks']),
            ($this->widget)(['id' => 'clock', 'type' => 'clock', 'source' => null]),
        ]]);

        $all = $this->actingAs($this->user)->getJson(route('screens.data', $this->screen))->assertOk()->json('data');
        $one = $this->actingAs($this->user)->getJson(route('screens.data', [$this->screen, 'widget' => 'list']))->json('data');

        expect(array_keys($all))->toBe(['issues', 'list', 'gauge', 'chart', 'health'])
            ->and(array_keys($all['issues']))->toBe(['open_issues', 'requests'])
            ->and($all['issues']['open_issues']['value'])->toEqual(2)
            ->and($all['list']['latest_issues']['rows'])->toHaveCount(2)
            ->and($all['gauge']['error_rate'])->toHaveKeys(['value', 'min', 'max', 'state', 'limits'])
            ->and($all['chart']['requests'])->toHaveKeys(['interval', 'red', 'series'])
            ->and($all['health']['checks'])->toHaveKeys(['summary', 'checks', 'check'])
            ->and(array_keys($one))->toBe(['list']);
    });

    it('resolves each rotated value over its own period', function () {
        $this->screen->update(['widgets' => [
            ($this->widget)(['id' => 'traffic', 'source' => 'requests', 'scope' => ['period' => '1h'], 'rotate' => ['p95', ['source' => 'apdex', 'seconds' => 30, 'scope' => ['period' => '7d'], 'options' => ['amber' => 0.9]]]]),
        ]]);

        $data = $this->actingAs($this->user)->getJson(route('screens.data', $this->screen))->assertOk()->json('data.traffic');

        expect(array_keys($data))->toBe(['requests', 'p95', 'apdex'])
            ->and($data['requests']['period'])->toBe('1h')
            ->and($data['p95']['period'])->toBe('1h')
            ->and($data['apdex']['period'])->toBe('7d')
            ->and($data['apdex']['limits']['amber'])->toEqual(0.9);
    });

    it('counts no viewers while broadcasting is off', function () {
        $this->actingAs($this->user)->getJson(route('screens.viewers', $this->screen))->assertExactJson(['count' => null]);
    });
});

describe('the designer\'s other projects', function () {
    it('only offers the team\'s own projects to pick', function () {
        Project::factory()->create(['name' => 'Foreign']);

        $this->actingAs($this->user)->get(route('screens.edit', $this->screen))->assertInertia(fn (Assert $page) => $page
            ->has('projects', 1)
            ->where('projects.0.name', $this->project->name));
    });
});

describe('placing widgets on cells', function () {
    beforeEach(fn () => Event::fake([ScreenChanged::class]));

    it('saves each widget on its cells and leaves the cells between empty', function () {
        $this->actingAs($this->user)->putJson(route('screens.layout', $this->screen), [
            'cols' => 4, 'rows' => 4, 'widgets' => [
                ($this->widget)(['id' => 'middle', 'x' => 1, 'y' => 1, 'w' => 2, 'h' => 2]),
                ($this->widget)(['id' => 'corner', 'x' => 3, 'y' => 3]),
            ],
        ])->assertOk();

        expect(array_map(fn (array $w): array => [$w['id'], $w['x'], $w['y'], $w['w'], $w['h']], $this->screen->refresh()->widgets))
            ->toBe([['middle', 1, 1, 2, 2], ['corner', 3, 3, 1, 1]]);
    });

    it('saves a widget resized from its left or top edge, moved and larger at once', function () {
        $this->actingAs($this->user)->putJson(route('screens.layout', $this->screen), [
            'cols' => 4, 'rows' => 4, 'widgets' => [
                ($this->widget)(['id' => 'a', 'x' => 0, 'y' => 0]),
                ($this->widget)(['id' => 'b', 'x' => 1, 'y' => 0, 'w' => 3, 'h' => 2]),
            ],
        ])->assertOk();

        expect($this->screen->refresh()->widgets[1])->toMatchArray(['x' => 1, 'y' => 0, 'w' => 3, 'h' => 2]);
    });

    it('refuses two widgets on one cell', function () {
        $this->actingAs($this->user)->putJson(route('screens.layout', $this->screen), [
            'cols' => 4, 'rows' => 4, 'widgets' => [
                ($this->widget)(['id' => 'a', 'x' => 0, 'y' => 0, 'w' => 2, 'h' => 2]),
                ($this->widget)(['id' => 'b', 'x' => 1, 'y' => 1]),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['widgets.1.x']);
    });

    it('never stores an overlap, however the action is called', function () {
        $widgets = [($this->widget)(['id' => 'a', 'x' => 0, 'y' => 0, 'w' => 2]), ($this->widget)(['id' => 'b', 'x' => 1, 'y' => 0])];

        expect(fn () => app(UpdateScreenLayoutAction::class)->execute($this->screen, 4, 4, $widgets))->toThrow(OverlappingWidgetsException::class)
            ->and($this->screen->refresh()->widgets[0]['id'])->toBe('requests');
    });

    it('refuses a position off the scale', function (array $at, string $error) {
        $this->actingAs($this->user)->putJson(route('screens.layout', $this->screen), [
            'cols' => 4, 'rows' => 4, 'widgets' => [($this->widget)($at)],
        ])->assertUnprocessable()->assertJsonValidationErrors($error);
    })->with([
        'a negative column' => [['x' => -1, 'y' => 0], 'widgets.0.x'],
        'past the widest grid' => [['x' => 12, 'y' => 0], 'widgets.0.x'],
        'not a number' => [['x' => 0, 'y' => 'top'], 'widgets.0.y'],
    ]);

    it('keeps a widget that no longer fits a smaller grid, for the designer to place again', function () {
        $this->actingAs($this->user)->putJson(route('screens.layout', $this->screen), [
            'cols' => 4, 'rows' => 2, 'widgets' => [($this->widget)(['x' => 0, 'y' => 2])],
        ])->assertOk();

        expect($this->screen->refresh()->widgets[0])->toMatchArray(['x' => 0, 'y' => 2]);
    });

    it('flows widgets from an older designer, without positions, like before', function () {
        $this->actingAs($this->user)->putJson(route('screens.layout', $this->screen), [
            'cols' => 3, 'rows' => 3, 'widgets' => [
                ($this->widget)(['id' => 'a', 'w' => 2]),
                ($this->widget)(['id' => 'b', 'w' => 2]),
            ],
        ])->assertOk();

        expect(array_map(fn (array $w): array => [$w['x'], $w['y']], $this->screen->refresh()->widgets))->toBe([[0, 0], [0, 1]]);
    });

    it('keeps a widget on its cells when only its settings are saved', function () {
        $this->actingAs($this->user)->putJson(route('screens.widget', [$this->screen, 'traffic']), [
            'widget' => ($this->widget)(['type' => 'chart', 'source' => 'p95', 'x' => 0, 'y' => 3, 'w' => 4, 'h' => 1]),
        ])->assertOk();

        expect($this->screen->refresh()->widgets[1])->toMatchArray(['source' => 'p95', 'x' => 1, 'y' => 0, 'w' => 2, 'h' => 2]);
    });

    it('positions an old screen for the designer and kiosk without storing anything', function () {
        $this->screen->update(['widgets' => array_map(fn (array $w): array => array_diff_key($w, ['x' => 0, 'y' => 0]), $this->screen->widgets)]);

        $this->actingAs($this->user)->get(route('screens.show', $this->screen))->assertInertia(fn (Assert $page) => $page
            ->where('screen.widgets.1.x', 1)
            ->where('screen.widgets.2.x', 3)
            ->where('screen.widgets.2.y', 0));
    });

    it('copies the positions when duplicating', function () {
        app(DuplicateScreenAction::class)->execute($this->screen);

        expect(Screen::query()->where('name', 'Ops (copy)')->sole()->widgets)->toBe($this->screen->widgets);
    });
});
