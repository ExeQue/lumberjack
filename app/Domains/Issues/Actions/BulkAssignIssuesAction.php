<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Data\IssueAssignment;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Models\User;

/**
 * One assignee, or none, for many issues from the issues list. Each person concerned
 * hears once for the whole action.
 */
class BulkAssignIssuesAction
{
    public function __construct(
        private readonly AssignIssueAction $assign,
    ) {
    }

    /**
     * @param  list<string>  $uuids
     * @return int issues it changed
     */
    public function execute(Project $project, array $uuids, ?User $assignee, User $by): int
    {
        $changes = [];

        foreach (Issue::query()->with('assignee')->where('project_id', $project->id)->whereIn('uuid', $uuids)->get() as $issue) {
            $from = $issue->assignee;

            if ($from?->id === $assignee?->id) {
                continue;
            }

            $this->assign->execute($issue, $assignee, $by, announce: false);
            $changes[] = new IssueAssignment($issue, $from, $assignee);
        }

        $this->assign->announce($changes, $by);

        return count($changes);
    }
}
