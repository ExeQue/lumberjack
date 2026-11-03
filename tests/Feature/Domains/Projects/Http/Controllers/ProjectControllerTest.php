<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Teams\CreatesTeamMembers;

covers(ProjectController::class);

uses(CreatesTeamMembers::class);

describe('creating a project', function () {
    it('takes the issue prefix chosen for it', function () {
        ['user' => $user] = $this->memberWithProject();

        $this->actingAs($user)->post(route('projects.store'), ['name' => 'Eazy Photo', 'issue_prefix' => 'ep'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('secret')
            ->assertRedirectContains('section=install');

        expect(Project::query()->where('name', 'Eazy Photo')->sole()->issuePrefix())->toBe('EP');
    });

    it('wants a prefix, of letters and digits, not taken in the team', function () {
        ['user' => $user, 'project' => $existing] = $this->memberWithProject();
        $existing->forceFill(['issue_prefix' => 'SHOP'])->save();

        $this->actingAs($user)->post(route('projects.store'), ['name' => 'A'])->assertSessionHasErrors('issue_prefix');
        $this->actingAs($user)->post(route('projects.store'), ['name' => 'A', 'issue_prefix' => 'shop'])->assertSessionHasErrors('issue_prefix');
        $this->actingAs($user)->post(route('projects.store'), ['name' => 'A', 'issue_prefix' => '9LIVES'])->assertSessionHasErrors('issue_prefix');

        expect(Project::query()->where('name', 'A')->exists())->toBeFalse();
    });
});

describe('the projects page', function () {
    it('names an offline agent with its host, beside an online one on the same host', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        ProjectAgent::factory()->for($project)->create(['host' => 'web-1', 'name' => 'web', 'last_seen_at' => now()]);
        ProjectAgent::factory()->for($project)->offline()->create(['host' => 'web-1', 'name' => '']);
        ProjectAgent::factory()->for($project)->offline()->create(['host' => 'web-2', 'name' => 'worker']);

        $this->actingAs($user)->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projects.0.offline_agents', ['unnamed agent on web-1', 'worker on web-2']));
    });

    it('shows each project\'s day in a few grouped queries', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $project->forceFill(['name' => 'Aa shop'])->save();
        $quiet = Project::factory()->for($project->team)->create(['name' => 'Zz quiet']);

        foreach ([100, 200, 300] as $ms) {
            RequestRun::factory()->for($project)->create(['duration_us' => $ms * 1000, 'occurred_at' => now()->subHour()]);
        }

        JobRun::factory()->for($project)->create(['status' => JobRunStatus::Failed, 'created_at' => now()->subHour()]);
        JobRun::factory()->for($project)->create(['status' => JobRunStatus::Processed, 'created_at' => now()->subHour()]);
        $issue = Issue::factory()->for($project)->create();
        Occurrence::factory()->for($issue)->count(2)->create(['project_id' => $project->id, 'occurred_at' => now()->subHour()]);

        $this->actingAs($user)->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->where('projects.0.p95', 300_000)
                ->where('projects.0.errors', 2)
                ->where('projects.0.open_issues', 1)
                ->where('projects.0.job_failure_rate', 0.5)
                ->where('projects.1.name', $quiet->name)
                ->where('projects.1.p95', null)
                ->where('projects.1.errors', 0)
                ->where('projects.1.job_failure_rate', null));
    });
});
