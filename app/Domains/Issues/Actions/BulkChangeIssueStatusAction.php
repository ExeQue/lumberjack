<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Data\IssueAssignment;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Events\IssuesStatusChangedInBulk;
use App\Domains\Issues\Models\Issue;
use App\Domains\Mcp\Models\McpToken;
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
        private readonly AssignIssueAction $assign,
    ) {
    }

    /**
     * @param  list<string>  $uuids
     * @param  McpToken|null  $token  set when an MCP token made the change; it is named instead of a person
     * @return int issues it changed
     */
    public function execute(Project $project, array $uuids, IssueStatus $status, ?User $by = null, ?McpToken $token = null): int
    {
        $issues = Issue::query()->with('assignee')->where('project_id', $project->id)->whereIn('uuid', $uuids)->get()
            ->reject(fn (Issue $issue): bool => $issue->status === $status);

        $single = $issues->count() === 1;
        $unassigned = [];

        foreach ($issues as $issue) {
            $from = $issue->assignee;
            $this->change->execute($issue, $status, $by, token: $token, announce: $single);

            if ($from !== null && $issue->assigned_user_id === null) {
                $unassigned[] = new IssueAssignment($issue, $from, null);
            }
        }

        if (! $single) {
            // Resolving takes the issues off their assignees: one message per person.
            $this->assign->announce($unassigned, $by, $token, resolved: true);
        }

        if ($issues->count() > 1) {
            $actor = $token !== null ? __('alerts.messages.via_mcp', ['token' => $token->name]) : ($by->name ?? __('alerts.messages.unknown_author'));
            IssuesStatusChangedInBulk::dispatch($project, array_values($issues->map(fn (Issue $issue): int => $issue->id)->all()), $status, $actor, $token === null ? $by?->id : null);
        }

        return $issues->count();
    }
}
