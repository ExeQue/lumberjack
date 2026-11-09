<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Http\Controllers\ScheduleController;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Shared\ReadsQueryCounts;

covers(ScheduleController::class);

uses(ReadsQueryCounts::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->task = ScheduledTask::factory()->for($this->project)->create(['command' => 'artisan reports:send']);
    ScheduledTaskRun::factory()->for($this->task, 'task')->count(3)->create();
});

describe('the schedule page', function () {
    it('lists the tasks with their recent runs, and the runs of the selected task', function () {
        $user = User::factory()->inTeam($this->project->team)->create();

        $this->actingAs($user)
            ->get(route('schedule.index', ['project' => $this->project, 'task' => $this->task->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('schedule/index')
                ->where('tasks.0.name', 'artisan reports:send')
                ->where('tasks.0.command', 'artisan reports:send')
                ->where('tasks.0.recent', ['finished', 'finished', 'finished'])
                ->has('runs', 3));
    });

    it('sorts by the next run on request, with tasks without one last', function () {
        $this->task->update(['name' => 'a-later', 'next_run_at' => now()->addHours(2)]);
        ScheduledTask::factory()->for($this->project)->create(['name' => 'b-sooner', 'next_run_at' => now()->addMinute()]);
        ScheduledTask::factory()->for($this->project)->create(['name' => 'c-unknown', 'next_run_at' => null]);
        $user = User::factory()->inTeam($this->project->team)->create();

        $this->actingAs($user)
            ->get(route('schedule.index', ['project' => $this->project, 'sort' => 'next']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'next')
                ->where('tasks.0.name', 'b-sooner')
                ->where('tasks.1.name', 'a-later')
                ->where('tasks.2.name', 'c-unknown'));
    });
});

describe('the queries of the schedule page', function () {
    it('reads the recent runs of every task in one query, at most twenty each', function () {
        $user = User::factory()->inTeam($this->project->team)->create();
        $page = fn () => $this->actingAs($user)->get(route('schedule.index', $this->project))->assertOk();
        $one = $this->queriesRunBy($page);

        ScheduledTask::factory()->for($this->project)->count(3)->sequence(fn ($sequence) => ['name' => "artisan task:{$sequence->index}"])
            ->has(ScheduledTaskRun::factory()->count(ScheduleController::RECENT_RUNS + 2), 'runs')->create();

        expect($this->queriesRunBy($page))->toBe($one);
        $page()->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 4)
            ->where('tasks.1.recent', fn ($recent) => count($recent) === ScheduleController::RECENT_RUNS));
    });
});

describe('a late task', function () {
    it('is late a minute after its time, not in the seconds it takes to start and report', function () {
        $user = User::factory()->inTeam($this->project->team)->create();
        $this->task->update(['next_run_at' => now()->subSeconds(10), 'last_run_at' => now()->subMinutes(5)]);

        $this->actingAs($user)->get(route('schedule.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('tasks.0.late', false));

        $this->task->update(['next_run_at' => now()->subMinutes(2)]);

        $this->actingAs($user)->get(route('schedule.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('tasks.0.late', true));
    });
});

describe('forgetting a task', function () {
    it('deletes the task and its runs', function () {
        $admin = User::factory()->inTeam($this->project->team)->create();

        $this->actingAs($admin)->delete(route('schedule.destroy', [$this->project, $this->task]))->assertRedirect();

        expect(ScheduledTask::count())->toBe(0)->and(ScheduledTaskRun::count())->toBe(0);
    });

    it('is refused to a member who cannot administer the project', function () {
        $member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();

        $this->actingAs($member)->delete(route('schedule.destroy', [$this->project, $this->task]))->assertForbidden();

        expect(ScheduledTask::count())->toBe(1);
    });
});
