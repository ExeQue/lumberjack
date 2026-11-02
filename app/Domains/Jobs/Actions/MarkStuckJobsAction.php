<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Actions;

use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;

/**
 * A run still processing past its own timeout plus a margin lost its worker.
 */
class MarkStuckJobsAction
{
    public function __construct(
        private readonly BroadcastProjectChangeAction $broadcast,
    ) {
    }

    public function execute(): int
    {
        $now = now();
        $projects = [];
        $marked = 0;

        JobRun::query()
            ->whereStatus(JobRunStatus::Processing)
            ->whereNotNull('started_at')
            ->where('started_at', '<', $now->copy()->subSeconds(JobRun::STUCK_MARGIN_SECONDS))
            ->eachById(function (JobRun $run) use ($now, &$projects, &$marked): void {
                $limit = ($run->timeout ?: JobRun::DEFAULT_TIMEOUT_SECONDS) + JobRun::STUCK_MARGIN_SECONDS;

                if ($run->started_at === null || $run->started_at->diffInSeconds($now) < $limit) {
                    return;
                }

                $run->update(['status' => JobRunStatus::Stuck]);
                $projects[$run->project_id] = true;
                $marked++;
            });

        foreach (array_keys($projects) as $projectId) {
            $this->broadcast->execute($projectId, ChangeKind::Jobs);
        }

        return $marked;
    }
}
