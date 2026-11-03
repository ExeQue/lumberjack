<?php

declare(strict_types=1);

use App\Domains\Overview\Http\Controllers\OverviewController;
use App\Domains\Performance\Http\Controllers\QueryController;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(OverviewController::class, QueryController::class);

describe('deferred statistics', function () {
    it('paints the overview first and loads its figures after', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();
        RequestRun::factory()->for($project)->count(2)->create(['occurred_at' => now()->subMinutes(5)]);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/overview')
                ->missing('overview')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('overview.requests', 2)));
    });

    it('paints the queries page first and loads its statistics after', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();

        $this->actingAs($user)
            ->get(route('queries.index', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('queries/index')
                ->has('connections')
                ->missing('stats')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('stats.calls', 0)));
    });
});
