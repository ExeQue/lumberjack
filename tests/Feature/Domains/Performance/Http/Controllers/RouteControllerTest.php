<?php

declare(strict_types=1);

use App\Domains\Performance\Http\Controllers\RouteController;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Performance\Support\RequestTraffic;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    it('lists browser pages with the p75 of each web vital', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();
        $row = ['project_id' => $project->id, 'environment' => 'production', 'route' => '/checkout', 'occurred_at' => now()->subMinutes(5)];
        DB::table('web_vitals')->insert([
            [...$row, 'lcp_us' => 1_000_000, 'inp_us' => null, 'cls' => 0.0],
            [...$row, 'lcp_us' => 2_000_000, 'inp_us' => 100_000, 'cls' => 0.05],
            [...$row, 'lcp_us' => 3_000_000, 'inp_us' => 300_000, 'cls' => 0.2],
            [...$row, 'lcp_us' => 9_000_000, 'inp_us' => 600_000, 'cls' => 0.3],
            [...$row, 'route' => '/old', 'occurred_at' => now()->subDays(40), 'lcp_us' => 1, 'inp_us' => 1, 'cls' => 0.0],
        ]);

        $this->actingAs($user)
            ->get(route('routes.index', ['project' => $project]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vitals', 1)
                // a missing INP is left out, not counted as zero: p75 of three values is the third
                ->where('vitals.0', ['route' => '/checkout', 'views' => 4, 'lcp_us' => 3_000_000, 'inp_us' => 600_000, 'cls' => 0.2]));
    });
});
