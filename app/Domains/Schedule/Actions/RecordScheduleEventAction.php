<?php

declare(strict_types=1);

namespace App\Domains\Schedule\Actions;

use App\Domains\Ingest\Data\Envelope;
use App\Domains\Ingest\Support\SignalFactory;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;

/**
 * One event from the app's scheduler: a task started, finished, failed or was skipped.
 * A failed run becomes an issue; so does a command that exits with an error code.
 */
class RecordScheduleEventAction
{
    public function __construct(
        private readonly RecordOccurrenceAction $recordOccurrenceAction,
        private readonly SignalFactory $signalFactory,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function execute(Project $project, array $event, Envelope $envelope): ?ScheduledTaskRun
    {
        $name = Envelope::short($event['name'] ?? null, 255);
        $uuid = Envelope::short($event['uuid'] ?? null, 36);
        $status = match ($event['status'] ?? null) {
            'started' => ScheduledRunStatus::Running,
            'finished' => ScheduledRunStatus::Finished,
            'failed' => ScheduledRunStatus::Failed,
            'skipped' => ScheduledRunStatus::Skipped,
            default => null,
        };

        if ($name === null || $uuid === null || $status === null) {
            return null;
        }

        $at = Envelope::time($event['timestamp'] ?? null);
        $exitCode = isset($event['exit_code']) && is_numeric($event['exit_code']) ? (int) $event['exit_code'] : null;

        // A command that returns an error code failed, exception or not.
        if ($status === ScheduledRunStatus::Finished && $exitCode !== null && $exitCode !== 0) {
            $status = ScheduledRunStatus::Failed;
        }

        $task = ScheduledTask::query()->firstOrNew(['project_id' => $project->id, 'environment' => $envelope->environment ?? '', 'name' => $name]);
        $task->fill([
            'command' => Envelope::short($event['command'] ?? null, 1000) ?? $task->command,
            'expression' => Envelope::short($event['expression'] ?? null) ?? ($task->expression ?? '* * * * *'),
            'timezone' => Envelope::short($event['timezone'] ?? null) ?? $task->timezone,
            'next_run_at' => isset($event['next_run_at']) ? Envelope::time($event['next_run_at']) : $task->next_run_at,
            'last_seen_at' => $at,
        ])->save();

        $run = ScheduledTaskRun::query()->firstOrNew(['project_id' => $project->id, 'run_uuid' => $uuid]);
        $run->fill([
            'scheduled_task_id' => $task->id,
            'trace_id' => Envelope::short($event['trace_id'] ?? null) ?? $run->trace_id,
            'environment' => $envelope->environment,
            'host' => $envelope->host,
        ]);

        // Only an event that says how its run was picked changes it; an older SDK says nothing.
        if (array_key_exists('sample_rate', $event) || array_key_exists('sampled', $event)) {
            $run->fill(Envelope::sampling($event));
        }

        // A late "started" never rolls back a run that already ended.
        if (! $run->exists || $status !== ScheduledRunStatus::Running) {
            $run->status = $status;
        }

        if ($status === ScheduledRunStatus::Running || $status === ScheduledRunStatus::Skipped) {
            $run->started_at ??= $at;
        } else {
            $run->finished_at = $at;
            $run->exit_code = $exitCode;
            $run->duration_us = isset($event['duration_ms']) ? Envelope::micros($event['duration_ms']) : $run->duration_us;
            $run->started_at ??= $run->duration_us !== null ? $at->subMicroseconds($run->duration_us) : $at;
        }

        // An artisan command that exits non-zero reports itself as a failed command run,
        // so its scheduled run does not make a second issue; its exception, if any, still does.
        $commandReportsItself = str_starts_with($task->command ?? $task->name, 'artisan ') && ! is_array($event['exception'] ?? null);

        if ($status === ScheduledRunStatus::Failed && $run->occurrence_id === null && ! $commandReportsItself) {
            $run->occurrence_id = $this->recordOccurrenceAction->execute($project, $this->failure($project, $task, $event, $envelope, $at, $exitCode))->id;
        }

        $run->save();

        if ($run->status !== ScheduledRunStatus::Running) {
            $task->update(['last_status' => $run->status, 'last_run_at' => $run->started_at, 'last_duration_us' => $run->duration_us]);
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function failure(Project $project, ScheduledTask $task, array $event, Envelope $envelope, CarbonImmutable $at, ?int $exitCode): IssueSignal
    {
        $context = ['schedule' => ['task' => $task->name, 'expression' => $task->expression, 'exit_code' => $exitCode]];

        if (is_array($event['exception'] ?? null)) {
            return $this->signalFactory->fromException(
                [...$event['exception'], 'trace_id' => $event['trace_id'] ?? null, 'timestamp' => $event['timestamp'] ?? null, 'context' => $context],
                $envelope,
                IssueSource::Server,
                $project->scrub_keys ?? [],
            );
        }

        return new IssueSignal(
            source: IssueSource::Server,
            title: 'Scheduled task failed: '.$task->name,
            message: "Exited with code {$exitCode}.",
            file: null,
            line: null,
            stacktrace: [],
            context: $context,
            occurredAt: $at,
            traceId: Envelope::short($event['trace_id'] ?? null),
            environment: $envelope->environment,
            release: $envelope->release,
            host: $envelope->host,
            customFingerprint: 'schedule-failed:'.$task->environment.':'.$task->name,
        );
    }
}
