<?php

declare(strict_types=1);

namespace App\Domains\Schedule\Actions;

use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use Illuminate\Support\Facades\DB;

/**
 * Forgets a task taken off the schedule, with its runs. If the app still runs
 * it, it comes back with its next run.
 */
class DeleteScheduledTaskAction
{
    public function execute(ScheduledTask $task): void
    {
        DB::transaction(function () use ($task): void {
            ScheduledTaskRun::query()->where('scheduled_task_id', $task->id)->delete();
            $task->delete();
        });
    }
}
