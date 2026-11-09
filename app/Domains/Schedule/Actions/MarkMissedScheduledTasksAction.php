<?php

declare(strict_types=1);

namespace App\Domains\Schedule\Actions;

use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Str;
use Throwable;

/**
 * A task whose next run is overdue by more than the grace period was missed:
 * the scheduler did not run, or did not run it. Each missed run becomes a run
 * row and an occurrence on one issue per task.
 */
class MarkMissedScheduledTasksAction
{
    public function __construct(
        private readonly RecordOccurrenceAction $recordOccurrenceAction,
        private readonly BroadcastProjectChangeAction $broadcast,
    ) {
    }

    public function execute(): int
    {
        $now = CarbonImmutable::now();
        $projects = [];
        $marked = 0;

        ScheduledTask::query()
            ->with('project')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<', $now->subSeconds(ScheduledTask::GRACE_SECONDS))
            ->where('last_seen_at', '>=', $now->subHours(ScheduledTask::FORGOTTEN_AFTER_HOURS))
            ->eachById(function (ScheduledTask $task) use ($now, &$projects, &$marked): void {
                $due = $task->next_run_at;

                if ($due === null || $task->project === null || ($task->last_run_at !== null && $task->last_run_at->gte($due))) {
                    return;
                }

                $occurrence = $this->recordOccurrenceAction->execute($task->project, new IssueSignal(
                    source: IssueSource::Server,
                    title: 'Scheduled task missed: '.$task->name,
                    message: "Due at {$due->toIso8601String()} ({$task->expression}), but Lumberjack received no record of this run.",
                    file: null,
                    line: null,
                    stacktrace: [],
                    context: ['schedule' => ['task' => $task->name, 'expression' => $task->expression, 'due_at' => $due->toIso8601String()]],
                    occurredAt: $now,
                    environment: $task->environment !== '' ? $task->environment : null,
                    customFingerprint: 'schedule-missed:'.$task->environment.':'.$task->name,
                ));

                ScheduledTaskRun::query()->create([
                    'project_id' => $task->project_id,
                    'scheduled_task_id' => $task->id,
                    'occurrence_id' => $occurrence->id,
                    'run_uuid' => (string) Str::uuid(),
                    'environment' => $task->environment !== '' ? $task->environment : null,
                    'status' => ScheduledRunStatus::Missed,
                    'started_at' => $due,
                ]);

                $task->update([
                    'last_status' => ScheduledRunStatus::Missed,
                    'missed_at' => $due,
                    'next_run_at' => $this->nextAfter($task, $due),
                ]);

                $projects[$task->project_id] = true;
                $marked++;
            });

        foreach (array_keys($projects) as $projectId) {
            $this->broadcast->execute($projectId, ChangeKind::Schedule);
        }

        return $marked;
    }

    /**
     * The run after the missed one, so a scheduler that stays down is flagged again.
     */
    private function nextAfter(ScheduledTask $task, CarbonImmutable $due): ?CarbonImmutable
    {
        try {
            $next = (new CronExpression($task->expression))->getNextRunDate($due->toDateTime(), 0, false, $task->timezone ?? 'UTC');
        } catch (Throwable) {
            return null;
        }

        return CarbonImmutable::instance($next);
    }
}
