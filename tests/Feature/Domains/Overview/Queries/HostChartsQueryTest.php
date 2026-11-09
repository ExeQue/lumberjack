<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Overview\Queries\HostChartsQuery;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(HostChartsQuery::class);

describe('the host charts on the overview', function () {
    it('draws one series per host and leaves a gap where a host did not report', function () {
        $this->travelTo('2026-11-09 12:00:30');
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();

        $web = ProjectAgent::factory()->for($project)->create(['host' => 'web-1']);
        $worker = ProjectAgent::factory()->for($project)->create(['host' => 'worker-1']);
        HostSample::factory()->for($web, 'agent')->create(['sampled_at' => now()->subMinutes(10)]);
        HostSample::factory()->for($worker, 'agent')->create(['sampled_at' => now()->subSeconds(10)]);

        $this->actingAs($user)
            ->get(route('projects.show', [$project, 'period' => '1h']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('host_charts')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->has('host_charts.disk_used', 2)
                    ->where('host_charts.memory_used.0.name', 'web-1')
                    ->where('host_charts.memory_used.1.name', 'worker-1')
                    ->where('host_charts.memory_used.1.points.30.v', 0.375)
                    ->where('host_charts.memory_used.0.points.30.v', null)));
    });

    it('splits response times, exceptions and failed jobs per host, within the environment', function () {
        $this->travelTo('2026-11-09 12:00:30');
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();
        $at = now()->subSeconds(10);

        RequestRun::factory()->for($project)->create(['host' => 'web-1', 'environment' => 'production', 'sampled' => true, 'duration_us' => 200_000, 'occurred_at' => $at]);
        Occurrence::factory()->create(['project_id' => $project->id, 'host' => 'web-1', 'environment' => 'production', 'occurred_at' => $at]);
        Occurrence::factory()->create(['project_id' => $project->id, 'host' => 'web-2', 'environment' => 'staging', 'occurred_at' => $at]);
        Occurrence::factory()->create(['project_id' => $project->id, 'host' => null, 'environment' => 'production', 'occurred_at' => $at]);
        JobRun::factory()->for($project)->failed()->create(['host' => 'worker-1', 'environment' => 'production', 'created_at' => $at]);

        $this->actingAs($user)
            ->get(route('projects.show', [$project, 'period' => '1h', 'environment' => 'production']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('host_charts.p95.0.name', 'web-1')
                    ->where('host_charts.p95.0.points.30.v', 200)
                    ->has('host_charts.occurrences', 1)
                    ->where('host_charts.occurrences.0.name', 'web-1')
                    ->where('host_charts.job_failures.0.name', 'worker-1')));
    });
});
