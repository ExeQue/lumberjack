<?php

declare(strict_types=1);

use App\Domains\Commands\Actions\RecordCommandEventAction;
use App\Domains\Commands\Enums\CommandRunStatus;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;

covers(RecordCommandEventAction::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->record = fn (array $event) => app(RecordCommandEventAction::class)->execute($this->project, [
        'type' => 'command', 'uuid' => '7d2f0c4b-0000-4000-8000-000000000001', 'name' => 'reports:send', ...$event,
    ], new Envelope('production', 'abc', 'worker-1'));
});

describe('a command run', function () {
    it('keeps the arguments and options it was given, scrubbed', function () {
        ($this->record)(['status' => 'started', 'arguments' => ['period' => 'daily'], 'options' => ['force' => true, 'api-token' => 'secret']]);
        $run = ($this->record)(['status' => 'finished', 'exit_code' => 0, 'duration_ms' => 1200]);

        expect($run?->status)->toBe(CommandRunStatus::Finished)
            ->and($run?->arguments)->toBe(['period' => 'daily'])
            ->and($run?->options)->toEqual(['force' => true, 'api-token' => '[filtered]'])
            ->and($run?->duration_us)->toBe(1_200_000)
            ->and(Issue::count())->toBe(0);
    });

    it('makes a non-zero exit an issue', function () {
        $run = ($this->record)(['status' => 'finished', 'exit_code' => 2]);

        expect($run?->status)->toBe(CommandRunStatus::Failed)
            ->and(Issue::sole()->title)->toBe('Command failed: reports:send');
    });

    it('does not add an issue when the run already sent the exception behind the exit code', function () {
        ($this->record)(['status' => 'finished', 'exit_code' => 1, 'exception' => true]);

        expect(Issue::count())->toBe(0);
    });
});
