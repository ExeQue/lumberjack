<?php

declare(strict_types=1);

use App\Domains\Projects\Http\Controllers\ProjectAgentController;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Teams\Enums\TeamRole;
use Tests\Support\Teams\CreatesTeamMembers;

covers(ProjectAgentController::class, ProjectAgent::class);

uses(CreatesTeamMembers::class);

describe('agents that are gone', function () {
    it('are forgotten after a day without a heartbeat', function () {
        $project = Project::factory()->create();
        $gone = ProjectAgent::factory()->for($project)->create(['host' => 'pod-a1', 'last_seen_at' => now()->subHours(25)]);
        $quiet = ProjectAgent::factory()->for($project)->create(['host' => 'pod-b2', 'last_seen_at' => now()->subHours(2)]);

        $this->artisan('model:prune', ['--model' => [ProjectAgent::class]])->assertSuccessful();

        expect(ProjectAgent::query()->pluck('host')->all())->toBe(['pod-b2']);
    });

    it('can be removed by someone who runs the project', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject(TeamRole::Admin);
        $agent = ProjectAgent::factory()->for($project)->create();

        $this->actingAs($user)->delete(route('agents.destroy', [$project, $agent]))->assertRedirect();

        expect(ProjectAgent::query()->count())->toBe(0);
    });

    it('cannot be removed by a member, nor through another project', function () {
        ['user' => $member, 'project' => $project] = $this->memberWithProject(TeamRole::Member);
        $agent = ProjectAgent::factory()->for($project)->create();
        ['user' => $owner, 'project' => $other] = $this->memberWithProject();

        $this->actingAs($member)->delete(route('agents.destroy', [$project, $agent]))->assertForbidden();
        $this->actingAs($owner)->delete(route('agents.destroy', [$other, $agent]))->assertNotFound();

        expect(ProjectAgent::query()->count())->toBe(1);
    });
});
