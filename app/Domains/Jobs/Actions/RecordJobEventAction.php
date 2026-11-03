<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Actions;

use App\Domains\Ingest\Data\Envelope;
use App\Domains\Ingest\Support\SignalFactory;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\Scrubber;

/**
 * One row per attempt. Events for the same attempt may arrive in any order
 * (different batches, different hosts), so every field is only ever filled in.
 */
class RecordJobEventAction
{
    public const int MAX_PARAMETER_BYTES = 8192;

    public function __construct(
        private readonly RecordOccurrenceAction $recordOccurrenceAction,
        private readonly SignalFactory $signalFactory,
        private readonly Scrubber $scrubber,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function execute(Project $project, array $event, Envelope $envelope): ?JobRun
    {
        $status = JobRunStatus::tryFrom((string) ($event['status'] ?? ''));
        $uuid = Envelope::short($event['uuid'] ?? null, 36);

        if ($status === null || $uuid === null || $status === JobRunStatus::Stuck) {
            return null;
        }

        $at = Envelope::time($event['timestamp'] ?? null);
        $attempt = max(1, (int) ($event['attempt'] ?? 1));

        $run = JobRun::query()->firstOrNew([
            'project_id' => $project->id,
            'job_uuid' => $uuid,
            'attempt' => $attempt,
        ]);

        $run->fill([
            'name' => mb_substr((string) ($event['name'] ?? $run->name ?? 'Unknown'), 0, 255),
            'connection' => Envelope::short($event['connection'] ?? null) ?? ($run->exists ? $run->connection : 'default'),
            'queue' => mb_substr((string) ($event['queue'] ?? $run->queue ?? 'default'), 0, 255),
            'trace_id' => Envelope::short($event['trace_id'] ?? null) ?? $run->trace_id,
            'environment' => $envelope->environment ?? $run->environment,
            'host' => $envelope->host ?? $run->host,
            'max_tries' => isset($event['max_tries']) ? (int) $event['max_tries'] : $run->max_tries,
            'timeout' => isset($event['timeout']) ? (int) $event['timeout'] : $run->timeout,
            'parameters' => $this->parameters($project, $event) ?? $run->parameters,
        ]);

        // Only an event that says how its run was picked changes it; a late "queued" says nothing.
        if (array_key_exists('sample_rate', $event) || array_key_exists('sampled', $event)) {
            $run->fill(Envelope::sampling($event));
        }

        match ($status) {
            JobRunStatus::Queued => $run->queued_at ??= $at,
            JobRunStatus::Processing => $run->started_at ??= $at,
            default => $run->finished_at = $at,
        };

        if ($status->isFinished() && isset($event['duration_ms'])) {
            $run->duration_us = Envelope::micros($event['duration_ms']);
        }

        // A late "queued" or "processing" never rolls back a finished attempt.
        if (! $run->exists || ! $run->status->isFinished() || $status->isFinished()) {
            if (! ($status === JobRunStatus::Queued && $run->exists)) {
                $run->status = $status;
            }
        }

        if ($status === JobRunStatus::Failed && is_array($event['exception'] ?? null)) {
            $signal = $this->signalFactory->fromException(
                [...$event['exception'], 'trace_id' => $event['trace_id'] ?? null, 'timestamp' => $event['timestamp'] ?? null,
                    'context' => ['job' => ['name' => $run->name, 'queue' => $run->queue, 'attempt' => $attempt, 'uuid' => $uuid]]],
                $envelope,
                IssueSource::Server,
                $project->scrub_keys ?? [],
            );
            $run->occurrence_id = $this->recordOccurrenceAction->execute($project, $signal)->id;
        }

        $run->save();

        return $run;
    }

    /**
     * The job's public properties as the SDK saw them when it was queued, scrubbed.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function parameters(Project $project, array $event): ?array
    {
        $parameters = $event['parameters'] ?? null;

        if (! is_array($parameters) || $parameters === []) {
            return null;
        }

        $parameters = $this->scrubber->scrub($parameters, $project->scrub_keys ?? []);

        return strlen((string) json_encode($parameters)) <= self::MAX_PARAMETER_BYTES ? $parameters : ['_truncated' => true];
    }
}
