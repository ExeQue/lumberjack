<?php

declare(strict_types=1);

use App\Domains\Ingest\Data\Envelope;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Actions\RecordScheduleEventAction;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;

covers(RecordScheduleEventAction::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->envelope = new Envelope('production', 'abc', 'worker-1');
    $this->event = fn (string $status, array $extra = []): array => [
        'type' => 'schedule', 'status' => $status, 'uuid' => '5c1e9b8a-0000-4000-8000-000000000001',
        'name' => 'artisan reports:send', 'expression' => '0 * * * *', 'timezone' => 'Europe/Copenhagen', ...$extra,
    ];
    $this->record = fn (array $event) => app(RecordScheduleEventAction::class)->execute($this->project, $event, $this->envelope);
});

describe('a run', function () {
    it('goes from running to finished, and the task remembers its last and next run', function () {
        ($this->record)(($this->event)('started'));
        $run = ($this->record)(($this->event)('finished', ['duration_ms' => 1500, 'exit_code' => 0, 'next_run_at' => '2026-11-02T18:00:00+01:00']));

        $task = ScheduledTask::sole();
        expect($run?->status)->toBe(ScheduledRunStatus::Finished)
            ->and($run?->duration_us)->toBe(1_500_000)
            ->and($task->environment)->toBe('production')
            ->and($task->last_status)->toBe(ScheduledRunStatus::Finished)
            ->and($task->next_run_at?->toIso8601String())->toBe('2026-11-02T17:00:00+00:00')
            ->and(Issue::count())->toBe(0);
    });

    it('counts a task that exits with an error code as failed, and makes it an issue', function () {
        $run = ($this->record)(($this->event)('finished', ['name' => 'backup.sh', 'duration_ms' => 20, 'exit_code' => 1]));

        expect($run?->status)->toBe(ScheduledRunStatus::Failed)
            ->and(Issue::sole()->title)->toBe('Scheduled task failed: backup.sh')
            ->and($run?->occurrence_id)->not->toBeNull();
    });

    it('leaves the issue for a failing artisan command to the command run itself', function () {
        $run = ($this->record)(($this->event)('finished', ['duration_ms' => 20, 'exit_code' => 1]));

        expect($run?->status)->toBe(ScheduledRunStatus::Failed)
            ->and(Issue::count())->toBe(0);
    });

    it('makes a failed run with an exception one issue, even if the failure is reported twice', function () {
        $exception = ['class' => 'RuntimeException', 'message' => 'SMTP down', 'file' => 'app/Console/Commands/SendReports.php', 'line' => 12, 'stacktrace' => []];

        ($this->record)(($this->event)('failed', ['exception' => $exception]));
        ($this->record)(($this->event)('failed', ['exception' => $exception]));

        expect(Issue::sole()->title)->toBe('RuntimeException')
            ->and(Issue::sole()->occurrences()->count())->toBe(1);
    });

    it('does not let a late start roll back a finished run', function () {
        ($this->record)(($this->event)('finished', ['exit_code' => 0]));
        $run = ($this->record)(($this->event)('started'));

        expect($run?->status)->toBe(ScheduledRunStatus::Finished);
    });
});

describe('the command of a task', function () {
    it('keeps the command beside the description that names the task', function () {
        ($this->record)(($this->event)('finished', ['name' => 'Send the reports', 'command' => 'artisan reports:send']));

        expect(ScheduledTask::sole())->name->toBe('Send the reports')->command->toBe('artisan reports:send');
    });

    it('keeps the description of the command', function () {
        ($this->record)(($this->event)('finished', ['command' => 'artisan reports:send', 'description' => 'Send the reports']));
        ($this->record)(($this->event)('finished', ['command' => 'artisan reports:send']));

        expect(ScheduledTask::sole())->name->toBe('artisan reports:send')->description->toBe('Send the reports');
    });

    it('keeps the command when a later event from an older SDK has none', function () {
        ($this->record)(($this->event)('finished', ['name' => 'Send the reports', 'command' => 'artisan reports:send']));
        ($this->record)(($this->event)('finished', ['name' => 'Send the reports']));

        expect(ScheduledTask::sole()->command)->toBe('artisan reports:send');
    });

    it('leaves the issue to the command run when an artisan command with a description exits non-zero', function () {
        ($this->record)(($this->event)('finished', ['name' => 'Send the reports', 'command' => 'artisan reports:send', 'exit_code' => 1]));

        expect(ScheduledTaskRun::sole()->occurrence_id)->toBeNull();
    });
});
