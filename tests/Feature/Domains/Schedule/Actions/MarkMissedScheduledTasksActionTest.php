<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Schedule\Actions\MarkMissedScheduledTasksAction;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTask;

covers(MarkMissedScheduledTasksAction::class);

describe('missed runs', function () {
    it('flags a task whose run is overdue past the grace period, and expects the run after it next', function () {
        $due = now()->startOfHour()->subHour();
        $task = ScheduledTask::factory()->create(['last_run_at' => $due->copy()->subHour(), 'next_run_at' => $due, 'last_seen_at' => $due->copy()->subHour()]);

        expect(app(MarkMissedScheduledTasksAction::class)->execute())->toBe(1);

        $task->refresh();
        expect($task->last_status)->toBe(ScheduledRunStatus::Missed)
            ->and($task->next_run_at?->equalTo($due->copy()->addHour()))->toBeTrue()
            ->and($task->runs()->sole()->status)->toBe(ScheduledRunStatus::Missed)
            ->and(Issue::sole()->title)->toBe('Scheduled task missed: artisan reports:send');
    });

    it('leaves a task alone that ran, is within the grace period, or has left the schedule', function () {
        ScheduledTask::factory()->create(['name' => 'ran', 'next_run_at' => now()->subHour(), 'last_run_at' => now()->subHour()]);
        ScheduledTask::factory()->create(['name' => 'grace', 'next_run_at' => now()->subMinute(), 'last_run_at' => now()->subHours(2)]);
        ScheduledTask::factory()->create(['name' => 'gone', 'next_run_at' => now()->subDays(3), 'last_run_at' => now()->subDays(4), 'last_seen_at' => now()->subDays(4)]);

        expect(app(MarkMissedScheduledTasksAction::class)->execute())->toBe(0)
            ->and(Issue::count())->toBe(0);
    });
});
