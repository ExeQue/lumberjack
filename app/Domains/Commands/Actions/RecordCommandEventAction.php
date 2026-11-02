<?php

declare(strict_types=1);

namespace App\Domains\Commands\Actions;

use App\Domains\Commands\Enums\CommandRunStatus;
use App\Domains\Commands\Models\CommandRun;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\Scrubber;

/**
 * One artisan command run: started, then finished with its exit code. A
 * non-zero exit becomes an issue, unless the run already sent the exception
 * that caused it.
 */
class RecordCommandEventAction
{
    public const int MAX_INPUT_BYTES = 8192;

    public function __construct(
        private readonly RecordOccurrenceAction $recordOccurrenceAction,
        private readonly Scrubber $scrubber,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function execute(Project $project, array $event, Envelope $envelope): ?CommandRun
    {
        $name = Envelope::short($event['name'] ?? null, 255);
        $uuid = Envelope::short($event['uuid'] ?? null, 36);
        $finished = ($event['status'] ?? null) === 'finished';

        if ($name === null || $uuid === null || (! $finished && ($event['status'] ?? null) !== 'started')) {
            return null;
        }

        $at = Envelope::time($event['timestamp'] ?? null);
        $run = CommandRun::query()->firstOrNew(['project_id' => $project->id, 'run_uuid' => $uuid]);

        $run->fill([
            'name' => $name,
            'trace_id' => Envelope::short($event['trace_id'] ?? null) ?? $run->trace_id,
            'environment' => $envelope->environment,
            'host' => $envelope->host,
            'arguments' => $this->input($project, $event['arguments'] ?? null) ?? $run->arguments,
            'options' => $this->input($project, $event['options'] ?? null) ?? $run->options,
        ]);

        if (! $finished) {
            $run->started_at ??= $at;
            $run->status = $run->exists && $run->status !== CommandRunStatus::Running ? $run->status : CommandRunStatus::Running;
        } else {
            $exitCode = isset($event['exit_code']) && is_numeric($event['exit_code']) ? (int) $event['exit_code'] : 0;
            $run->exit_code = $exitCode;
            $run->finished_at = $at;
            $run->duration_us = isset($event['duration_ms']) ? Envelope::micros($event['duration_ms']) : $run->duration_us;
            $run->started_at ??= $run->duration_us !== null ? $at->subMicroseconds($run->duration_us) : $at;
            $run->status = $exitCode === 0 ? CommandRunStatus::Finished : CommandRunStatus::Failed;

            if ($exitCode !== 0 && $run->occurrence_id === null && ! ($event['exception'] ?? false)) {
                $run->occurrence_id = $this->recordOccurrenceAction->execute($project, new IssueSignal(
                    source: IssueSource::Server,
                    title: 'Command failed: '.$name,
                    message: "Exited with code {$exitCode}.",
                    file: null,
                    line: null,
                    stacktrace: [],
                    context: ['command' => ['name' => $name, 'arguments' => $run->arguments, 'options' => $run->options, 'exit_code' => $exitCode]],
                    occurredAt: $at,
                    traceId: $run->trace_id,
                    environment: $envelope->environment,
                    release: $envelope->release,
                    host: $envelope->host,
                    customFingerprint: 'command-failed:'.($envelope->environment ?? '').':'.$name,
                ))->id;
            }
        }

        $run->save();

        return $run;
    }

    /**
     * Arguments or options as given, scrubbed and size-capped.
     *
     * @return array<string, mixed>|null
     */
    private function input(Project $project, mixed $input): ?array
    {
        if (! is_array($input) || $input === []) {
            return null;
        }

        $input = $this->scrubber->scrub($input, $project->scrub_keys ?? []);

        return strlen((string) json_encode($input)) <= self::MAX_INPUT_BYTES ? $input : ['_truncated' => true];
    }
}
