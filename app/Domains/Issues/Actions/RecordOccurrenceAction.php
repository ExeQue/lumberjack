<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Events\IssueCreated;
use App\Domains\Issues\Events\IssueRegressed;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Issues\Support\Fingerprinter;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Groups one error into its issue, re-opens a resolved issue as a regression,
 * and stores the occurrence. Ignored issues keep counting but never re-open.
 */
class RecordOccurrenceAction
{
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly DetectEscalationAction $detectEscalation,
        private readonly RecordIssueEventAction $history,
        private readonly ReturnIgnoredIssueAction $returnIgnored,
    ) {
    }

    public function execute(Project $project, IssueSignal $signal): Occurrence
    {
        $fingerprint = $this->fingerprinter->fingerprint($signal);

        try {
            $occurrence = $this->record($project, $signal, $fingerprint);
        } catch (UniqueConstraintViolationException) {
            // Another worker created the issue between our read and insert; it exists now.
            $occurrence = $this->record($project, $signal, $fingerprint);
        }

        if ($occurrence->issue !== null) {
            $this->detectEscalation->execute($occurrence->issue, $signal->environment);
        }

        return $occurrence;
    }

    private function record(Project $project, IssueSignal $signal, string $fingerprint): Occurrence
    {
        return DB::transaction(function () use ($project, $signal, $fingerprint): Occurrence {
            $issue = Issue::query()
                ->where('project_id', $project->id)
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            $at = $signal->occurredAt;

            if ($issue === null) {
                // The project row's lock hands out numbers one at a time.
                Project::query()->whereKey($project->id)->increment('last_issue_number');

                $issue = Issue::create([
                    'project_id' => $project->id,
                    'number' => (int) Project::query()->whereKey($project->id)->value('last_issue_number'),
                    'fingerprint' => $fingerprint,
                    'source' => $signal->source,
                    'status' => IssueStatus::Open,
                    'title' => mb_substr($signal->title, 0, 255),
                    'message' => mb_substr($signal->message, 0, 10_000),
                    'file' => $signal->file !== null ? mb_substr($signal->file, 0, 255) : null,
                    'line' => $signal->line,
                    'log_channel' => $signal->logChannel,
                    'last_release' => $signal->release,
                    'occurrences_count' => 1,
                    'users_count' => 0,
                    'first_seen_at' => $at,
                    'last_seen_at' => $at,
                    'surfaced_at' => now(),
                ]);

                $this->history->execute($issue, IssueEventType::Created, at: $at);
                IssueCreated::dispatch($issue, $signal->environment);
            } else {
                $regressed = $issue->status === IssueStatus::Resolved;
                $returns = $issue->status === IssueStatus::Ignored && $issue->ignore_until_count !== null
                    && $issue->occurrences_count + 1 >= $issue->ignore_until_count;

                $issue->fill([
                    'occurrences_count' => $issue->occurrences_count + 1,
                    'last_seen_at' => $at->greaterThan($issue->last_seen_at) ? $at : $issue->last_seen_at,
                    'last_release' => $signal->release ?? $issue->last_release,
                ]);

                if ($regressed) {
                    $issue->fill([
                        'status' => IssueStatus::Open,
                        'resolved_at' => null,
                        'regressed_at' => now(),
                        'surfaced_at' => now(),
                    ]);
                }

                $issue->save();

                if ($regressed) {
                    $this->history->execute($issue, IssueEventType::Regressed);
                    IssueRegressed::dispatch($issue, $signal->environment);
                }

                if ($returns) {
                    $this->returnIgnored->execute($issue, 'count', $signal->environment, ['occurrences' => $issue->occurrences_count]);
                }
            }

            $userId = $signal->userId();

            // The issue row is locked, so two workers cannot both count the same new user.
            if ($userId !== null && ! $issue->occurrences()->where('user_id', $userId)->exists()) {
                $issue->increment('users_count');
            }

            return $issue->occurrences()->create([
                'project_id' => $project->id,
                'user_id' => $userId,
                'trace_id' => $signal->traceId,
                'environment' => $signal->environment,
                'release' => $signal->release,
                'host' => $signal->host,
                'stacktrace' => $signal->stacktrace,
                'context' => $signal->context,
                'occurred_at' => $at,
            ]);
        });
    }
}
