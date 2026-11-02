<?php

declare(strict_types=1);

use App\Domains\Performance\Http\Controllers\RouteController;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(RouteController::class);

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
});
