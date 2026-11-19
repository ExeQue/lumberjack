<?php

declare(strict_types=1);

use App\Domains\Performance\Http\Controllers\RouteController;
use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestCount;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Performance\Support\RequestTraffic;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Performance\CreatesRequestCounts;

covers(RouteController::class, RouteStatisticsQuery::class, RequestTraffic::class);

uses(CreatesRequestCounts::class);

describe('the routes page', function () {
    it('lists routes with their series and the selected route in detail', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();
        RequestRun::factory()->for($project)->count(3)->create(['method' => 'GET', 'route' => '/dashboard', 'occurred_at' => now()->subMinutes(5)]);

        $this->actingAs($user)
            ->get(route('routes.index', ['project' => $project, 'route' => '/dashboard', 'method' => 'GET']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('routes/index')
                ->where('routes.0.route', '/dashboard')
                ->where('routes.0.requests', 3)
                ->where('routes.0.series', fn ($series) => array_sum($series->all()) === 3)
                ->where('detail.route', '/dashboard')
                ->has('detail.slowest', 3));
    });

    it('counts the queries of each slow run in its trace', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();
        $traced = RequestRun::factory()->for($project)->forRoute('GET', '/dashboard')->slow()->create(['trace_id' => 'trace-a', 'occurred_at' => now()->subMinutes(5)]);
        RequestRun::factory()->for($project)->forRoute('GET', '/dashboard')->create(['trace_id' => null, 'occurred_at' => now()->subMinutes(5)]);
        QueryRun::factory()->for($project)->inTrace('trace-a')->count(4)->create();

        $this->actingAs($user)
            ->get(route('routes.index', ['project' => $project, 'route' => '/dashboard', 'method' => 'GET']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.slowest.0.trace_id', $traced->trace_id)
                ->where('detail.slowest.0.queries', 4)
                ->where('detail.slowest.1.queries', null));
    });

    it('shows a url no route matched in detail, with its percentiles per bucket', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();

        foreach ([100, 200, 300, 400] as $ms) {
            RequestRun::factory()->for($project)->create(['method' => 'GET', 'route' => null, 'url' => '/legacy.php', 'duration_us' => $ms * 1000, 'occurred_at' => now()->subMinutes(5)]);
        }

        RequestRun::factory()->for($project)->create(['method' => 'GET', 'route' => '/other', 'url' => '/legacy.php', 'duration_us' => 9_000_000, 'occurred_at' => now()->subMinutes(5)]);

        $this->actingAs($user)
            ->get(route('routes.index', ['project' => $project, 'route' => '/legacy.php', 'method' => 'GET']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('detail.slowest', 4)
                ->where('detail.hourly', fn ($hourly) => collect($hourly)->contains(fn (array $b): bool => $b['p50'] === 200_000 && $b['p95'] === 400_000)));
    });

    it('scores each route by Apdex and splits its status codes', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();
        $at = ['method' => 'GET', 'route' => '/slow', 'occurred_at' => now()->subMinutes(5)];
        RequestRun::factory()->for($project)->create([...$at, 'duration_us' => 100_000, 'status_code' => 200]);
        RequestRun::factory()->for($project)->create([...$at, 'duration_us' => 1_000_000, 'status_code' => 404]);
        RequestRun::factory()->for($project)->create([...$at, 'duration_us' => 9_000_000, 'status_code' => 200]);
        RequestRun::factory()->for($project)->create([...$at, 'duration_us' => 100_000, 'status_code' => 500]);

        $this->actingAs($user)
            ->get(route('routes.index', ['project' => $project]))
            ->assertInertia(fn (Assert $page) => $page
                // satisfied 1, tolerating 1 (half), frustrated 2 (too slow, 5xx): 1.5 / 4
                ->where('routes.0.apdex', 0.38)
                ->where('routes.0.statuses', ['2xx' => 2, '3xx' => 0, '4xx' => 1, '5xx' => 1]));
    });

    it('splits a route by domain when there are several, and narrows to one picked', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();
        $at = ['method' => 'GET', 'route' => '/orders', 'route_name' => 'orders.index', 'occurred_at' => now()->subMinutes(5)];
        RequestRun::factory()->for($project)->count(2)->create([...$at, 'domain' => 'shop.example.com']);
        RequestRun::factory()->for($project)->create([...$at, 'domain' => 'api.example.com']);

        $this->actingAs($user)
            ->get(route('routes.index', ['project' => $project, 'sort' => 'count', 'route' => '/orders', 'method' => 'GET', 'route_domain' => 'api.example.com']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('domains', ['api.example.com', 'shop.example.com'])
                ->has('routes', 2)
                ->where('routes.0', fn ($r) => $r['domain'] === 'shop.example.com' && $r['requests'] === 2 && $r['route_name'] === 'orders.index' && array_sum($r['series']) === 2)
                ->where('routes.1.domain', 'api.example.com')
                ->where('detail.domain', 'api.example.com')
                ->where('detail.route_name', 'orders.index')
                ->has('detail.slowest', 1));

        $this->actingAs($user)
            ->get(route('routes.index', ['project' => $project, 'domain' => 'shop.example.com']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('routes', 1)
                ->where('routes.0.domain', null)
                ->where('routes.0.requests', 2));
    });

    it('keeps a route whole with one domain, and finds it by its name', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();
        RequestRun::factory()->for($project)->create(['route' => '/orders/{order}', 'route_name' => 'orders.show', 'domain' => 'shop.example.com', 'occurred_at' => now()->subMinutes(5)]);
        // Counted by an older SDK, without a domain: still the same route.
        $this->createRequestCount($project, 4, route: '/orders/{order}', minute: now()->subMinutes(2)->startOfMinute());
        RequestRun::factory()->for($project)->create(['route' => '/cart', 'domain' => 'shop.example.com', 'occurred_at' => now()->subMinutes(5)]);

        $this->actingAs($user)
            ->get(route('routes.index', ['project' => $project, 'search' => 'orders.sh']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('routes', 1)
                ->where('routes.0.route', '/orders/{order}')
                ->where('routes.0.route_name', 'orders.show')
                ->where('routes.0.domain', null)
                ->where('routes.0.requests', 5));
    });
});

describe('request kinds', function () {
    beforeEach(function () {
        $this->project = Project::factory()->create();
        $this->user = User::factory()->inTeam($this->project->team)->create();
        RequestRun::factory()->for($this->project)->forRoute('GET', '/dashboard')->count(3)->create(['occurred_at' => now()->subMinutes(5)]);
        RequestRun::factory()->for($this->project)->forRoute('GET', '/dashboard')->inertiaPartialReload()->count(2)->create(['occurred_at' => now()->subMinutes(5)]);
        RequestRun::factory()->for($this->project)->livewireCall('cart.checkout', 'save')->create(['occurred_at' => now()->subMinutes(5)]);
        // Counted by the SDK too: a partial reload stays apart from the full visit.
        RequestCount::query()->create(['project_id' => $this->project->id, 'environment' => 'production', 'minute' => now()->subMinutes(2)->startOfMinute(), 'method' => 'GET', 'domain' => 'example.test', 'route' => '/dashboard', 'kind' => 'inertia-partial', 'status_code' => 200, 'count' => 10, 'duration_us' => 1_000]);
    });

    it('keeps each kind of a route apart and opens the one picked', function () {
        $this->actingAs($this->user)
            ->get(route('routes.index', ['project' => $this->project, 'sort' => 'count', 'route' => '/dashboard', 'method' => 'GET', 'route_kind' => 'inertia-partial']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('routes', 3)
                ->where('routes.0', fn ($r) => $r['route'] === '/dashboard' && $r['kind'] === 'inertia-partial' && $r['requests'] === 12 && array_sum($r['series']) === 12)
                ->where('routes.1', fn ($r) => $r['route'] === '/dashboard' && $r['kind'] === null && $r['requests'] === 3)
                ->where('routes.2', fn ($r) => $r['route'] === 'livewire:cart.checkout@save' && $r['kind'] === 'livewire')
                ->where('detail.kind', 'inertia-partial')
                ->has('detail.slowest', 2));
    });

    it('opens an ordinary row by itself, and every kind of a route for a link without one', function (?string $routeKind, int $slowest) {
        $this->actingAs($this->user)
            ->get(route('routes.index', ['project' => $this->project, 'route' => '/dashboard', 'method' => 'GET', 'route_kind' => $routeKind]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.route_kind', $routeKind)
                ->where('detail.kind', null)
                ->has('detail.slowest', $slowest));
    })->with([
        'the ordinary row' => ['none', 3],
        'a link from before kinds' => [null, 5],
    ]);

    it('keeps a route whole outside the routes page', function () {
        $routes = collect(app(RouteStatisticsQuery::class)->get($this->project->id, Period::Day))->keyBy('route');

        expect($routes)->toHaveCount(2)
            ->and($routes['/dashboard'])->toMatchArray(['kind' => null, 'requests' => 15]);
    });

    it('narrows the table to one kind, or to ordinary requests', function (string $kind, string $route, ?string $expected) {
        $this->actingAs($this->user)
            ->get(route('routes.index', ['project' => $this->project, 'kind' => $kind]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.kind', $kind)
                ->has('routes', 1)
                ->where('routes.0.route', $route)
                ->where('routes.0.kind', $expected));
    })->with([
        'livewire updates' => ['livewire', 'livewire:cart.checkout@save', 'livewire'],
        'ordinary requests' => ['none', '/dashboard', null],
    ]);
});
