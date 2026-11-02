<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\IssueEventType;
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

    public function execute(Issue $issue, ?User $assignee, ?User $by = null, ?McpToken $token = null): Issue
    {
        if ($assignee !== null && ! $assignee->belongsToTeam($issue->project->team)) {
            throw AssigneeNotInTeamException::create();
        }

        $from = $issue->assignee;
        $issue->update(['assigned_user_id' => $assignee?->id]);

        if ($from?->id !== $assignee?->id) {
            $this->history->execute($issue, IssueEventType::Assigned, $by, $token, ['from' => $from?->name, 'to' => $assignee?->name]);
        }

        $this->broadcast->issueUpdated($issue->project_id, $issue->uuid);

        return $issue;
    }
}
