<?php

declare(strict_types=1);

use App\Domains\Performance\Http\Controllers\TraceController;
use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Performance\Models\SpanRun;
use App\Domains\Performance\Queries\TraceQuery;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Teams\CreatesTeamMembers;

covers(TraceController::class, TraceQuery::class);

uses(CreatesTeamMembers::class);

describe('a trace', function () {
    it('lays the request, its queries and its spans out on one time axis', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $start = now()->startOfSecond();
        RequestRun::factory()->for($project)->create(['trace_id' => 't1', 'method' => 'POST', 'route' => '/checkout', 'duration_us' => 500_000, 'occurred_at' => $start]);
        SpanRun::query()->create(['project_id' => $project->id, 'trace_id' => 't1', 'kind' => 'http', 'name' => 'POST', 'target' => 'api.stripe.com/v1/charges', 'duration_us' => 300_000, 'attributes' => ['status' => 201], 'occurred_at' => $start->copy()->addMilliseconds(100)]);
        QueryRun::factory()->for($project)->create(['trace_id' => 't1', 'sql' => 'select 1', 'duration_us' => 1_000, 'occurred_at' => $start->copy()->addMilliseconds(20)]);
        SpanRun::query()->create(['project_id' => $project->id, 'trace_id' => 'other', 'kind' => 'cache', 'name' => 'hit', 'target' => 'x', 'occurred_at' => $start]);

        $this->actingAs($user)->get(route('traces.show', [$project, 't1']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('traces/show')
                ->where('trace.requests.0.route', '/checkout')
                ->where('trace.requests.0.offset_us', 0)
                ->has('trace.timeline', 2)
                ->where('trace.timeline.0.type', 'query')
                ->where('trace.timeline.0.offset_us', 20_000)
                ->where('trace.timeline.1.kind', 'http')
                ->where('trace.timeline.1.offset_us', 100_000)
                ->where('trace.timeline.1.attributes.status', 201));
    });

    it('is not there for a trace id that recorded nothing', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();

        $this->actingAs($user)->get(route('traces.show', [$project, 'nothing-here']))->assertNotFound();
    });

    it('is not shown to someone outside the team', function () {
        ['project' => $project] = $this->memberWithProject();
        RequestRun::factory()->for($project)->create(['trace_id' => 't1']);

        $this->actingAs(User::factory()->inTeam()->create())->get(route('traces.show', [$project, 't1']))->assertForbidden();
    });
});
