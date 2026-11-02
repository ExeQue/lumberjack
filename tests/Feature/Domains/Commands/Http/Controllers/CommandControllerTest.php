<?php

declare(strict_types=1);

use App\Domains\Commands\Http\Controllers\CommandController;
use App\Domains\Commands\Models\CommandRun;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(CommandController::class);

describe('the commands page', function () {
    it('lists commands with their failures, and the runs of the chosen one with their input', function () {
        $project = Project::factory()->create();
        CommandRun::factory()->for($project)->count(2)->create();
        CommandRun::factory()->for($project)->failed()->create();
        CommandRun::factory()->for($project)->create(['name' => 'cache:clear', 'arguments' => null, 'options' => null]);
        $user = User::factory()->inTeam($project->team)->create();

        $this->actingAs($user)
            ->get(route('commands.index', ['project' => $project, 'command' => 'reports:send']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('commands/index')
                ->where('commands.0', fn ($c) => $c['name'] === 'reports:send' && $c['runs'] === 3 && $c['failed'] === 1)
                ->has('runs', 3)
                ->where('runs.0.options', ['force' => true]));
    });
});
