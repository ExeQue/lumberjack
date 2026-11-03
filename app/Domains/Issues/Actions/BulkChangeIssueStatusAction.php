<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Events\IssuesStatusChangedInBulk;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Models\User;

/**
 * The same status for many issues from the issues list. Resolving or ignoring them sends
 * one summary alert instead of one per issue; a single issue alerts as a normal change.
 */
class BulkChangeIssueStatusAction
{
    public function __construct(
        private readonly ChangeIssueStatusAction $change,
    ) {
    }

    /**
     * @param  list<string>  $uuids
     * @return int issues it changed
     */
    public function execute(Project $project, array $uuids, IssueStatus $status, User $by): int
    {
        $issues = Issue::query()->where('project_id', $project->id)->whereIn('uuid', $uuids)->get()
            ->reject(fn (Issue $issue): bool => $issue->status === $status);

        $single = $issues->count() === 1;
        $issues->each(fn (Issue $issue): Issue => $this->change->execute($issue, $status, $by, announce: $single));

        if ($issues->count() > 1 && ($status === IssueStatus::Resolved || $status === IssueStatus::Ignored)) {
            IssuesStatusChangedInBulk::dispatch($project, array_values($issues->map(fn (Issue $issue): int => $issue->id)->all()), $status, $by->name);
        }

        return $issues->count();
    }
}
