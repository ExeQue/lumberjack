<?php

declare(strict_types=1);

use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Projects\Models\Project;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(HandleInertiaRequests::class);

describe('the sidebar project', function () {
    it('counts open issues and the job runs that failed or got stuck in the last day', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();

        Issue::factory()->for($project)->count(2)->create();
        Issue::factory()->for($project)->resolved()->create();
        JobRun::factory()->for($project)->failed()->create();
        JobRun::factory()->for($project)->create(['status' => JobRunStatus::Stuck]);
        JobRun::factory()->for($project)->create();
        JobRun::factory()->for($project)->failed()->create(['created_at' => now()->subDays(2)]);

        $this->actingAs($user)
            ->get(route('issues.index', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sidebar_project.uuid', $project->uuid)
                ->where('sidebar_project.counts', ['issues' => 2, 'jobs' => 2, 'schedule' => 0, 'health' => 0, 'commands' => 0]));
    });

    it('lists each connected connector with its connections by name, leaving out apps and other projects', function () {
        $project = Project::factory()->create();
        $user = User::factory()->inTeam($project->team)->create();
        $shop = DataSourceConnection::factory()->for($project)->github()->create(['name' => 'acme/shop']);
        $api = DataSourceConnection::factory()->for($project)->github()->create(['name' => 'acme/api']);
        $ledger = DataSourceConnection::factory()->for($project)->mobilePay()->create(['name' => 'Oslo']);
        DataSourceConnection::factory()->for($project)->create();
        DataSourceConnection::factory()->umami()->create();

        $this->actingAs($user)
            ->get(route('issues.index', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sidebar_project.connectors', [
                    'github' => [['uuid' => $api->uuid, 'name' => 'acme/api'], ['uuid' => $shop->uuid, 'name' => 'acme/shop']],
                    'mobilepay' => [['uuid' => $ledger->uuid, 'name' => 'Oslo']],
                ]));
    });

    it('lists no connectors for a project without any', function () {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->inTeam($project->team)->create())
            ->get(route('issues.index', $project))
            ->assertInertia(fn (Assert $page) => $page->where('sidebar_project.connectors', []));
    });
});
