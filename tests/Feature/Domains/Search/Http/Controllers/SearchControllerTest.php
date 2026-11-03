<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Search\Http\Controllers\SearchController;
use App\Domains\Search\Queries\ProjectSearchQuery;
use App\Models\User;
use Tests\Support\Teams\CreatesTeamMembers;

covers(SearchController::class, ProjectSearchQuery::class);

uses(CreatesTeamMembers::class);

describe('the command palette search', function () {
    it('finds issues, routes, jobs and traces in the project', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        Issue::factory()->for($project)->create(['title' => 'Checkout failed']);
        RequestRun::factory()->for($project)->create(['method' => 'POST', 'route' => '/checkout', 'trace_id' => 'checkout-trace-1', 'occurred_at' => now()]);
        JobRun::factory()->for($project)->create(['name' => 'App\\Jobs\\ProcessCheckout', 'started_at' => now()]);

        $results = collect($this->actingAs($user)->getJson(route('projects.search', [$project, 'q' => 'checkout']))->assertOk()->json('results'));

        expect($results->pluck('group')->unique()->sort()->values()->all())->toBe(['issues', 'jobs', 'routes'])
            ->and($results->firstWhere('group', 'routes'))->toMatchArray(['label' => '/checkout', 'detail' => 'POST']);

        $this->actingAs($user)->getJson(route('projects.search', [$project, 'q' => 'checkout-trace-1']))
            ->assertJsonPath('results.0.group', 'traces')
            ->assertJsonPath('results.0.href', route('traces.show', [$project, 'checkout-trace-1']));
    });

    it('leaves other projects out', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        ['project' => $other] = $this->memberWithProject();
        Issue::factory()->for($other)->create(['title' => 'Checkout failed']);

        $this->actingAs($user)->getJson(route('projects.search', [$project, 'q' => 'checkout']))->assertJsonCount(0, 'results');
    });

    it('is not open to someone outside the team', function () {
        ['project' => $project] = $this->memberWithProject();

        $this->actingAs(User::factory()->inTeam()->create())->getJson(route('projects.search', [$project, 'q' => 'checkout']))->assertForbidden();
    });
});
