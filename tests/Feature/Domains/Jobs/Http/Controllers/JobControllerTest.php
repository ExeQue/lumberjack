<?php

declare(strict_types=1);

use App\Domains\Jobs\Http\Controllers\JobController;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(JobController::class);

it('shows the queues agents report on the jobs page', function () {
    $project = Project::factory()->create();
    $user = User::factory()->inTeam($project->team)->create();
    QueueSample::query()->create(['project_id' => $project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => 'default', 'size' => 7, 'sampled_at' => now()]);

    $this->actingAs($user)
        ->get(route('jobs.index', ['project' => $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/index')
            ->where('queue_stats.0.queue', 'default')
            ->where('queue_stats.0.size', 7));
});
