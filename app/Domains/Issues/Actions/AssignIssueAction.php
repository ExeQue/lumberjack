<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Data\IssueAssignment;
use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Events\IssuesAssigned;
use App\Domains\Issues\Exceptions\AssigneeNotInTeamException;
use App\Domains\Issues\Models\Issue;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;

class AssignIssueAction
{
    public function __construct(
        private readonly BroadcastProjectChangeAction $broadcast,
        private readonly RecordIssueEventAction $history,
    ) {
    }

    /**
     * @param  bool  $announce  false for bulk actions, which announce all their changes at once
     * @param  bool  $resolved  it is taken off its assignee because it was resolved
     */
    public function execute(Issue $issue, ?User $assignee, ?User $by = null, ?McpToken $token = null, bool $announce = true, bool $resolved = false): Issue
    {
        if ($assignee !== null && ! $assignee->belongsToTeam($issue->project->team)) {
            throw AssigneeNotInTeamException::create();
        }

        $from = $issue->assignee;
        $issue->update(['assigned_user_id' => $assignee?->id]);

        if ($from?->id !== $assignee?->id) {
            $this->history->execute($issue, IssueEventType::Assigned, $by, $token, ['from' => $from?->name, 'to' => $assignee?->name]);

            if ($announce) {
                $this->announce([new IssueAssignment($issue, $from, $assignee)], $by, $token, $resolved);
            }
        }

        $this->broadcast->issueUpdated($issue->project_id, $issue->uuid);

        return $issue;
    }

    /**
     * Tells the people concerned, once for all the changes of one action.
     *
     * @param  list<IssueAssignment>  $changes
     */
    public function announce(array $changes, ?User $by = null, ?McpToken $token = null, bool $resolved = false): void
    {
        if ($changes !== []) {
            IssuesAssigned::dispatch($changes, $by, $token, $resolved);
        }
    }
}
