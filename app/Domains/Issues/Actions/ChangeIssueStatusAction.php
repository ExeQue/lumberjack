<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Data\IgnoreCondition;
use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Events\IssueStatusChanged;
use App\Domains\Issues\Models\Issue;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;

/**
 * Resolve, ignore or re-open. The UI and the MCP tool both come through here.
 */
class ChangeIssueStatusAction
{
    public function __construct(
        private readonly BroadcastProjectChangeAction $broadcast,
        private readonly AddIssueNoteAction $addNote,
        private readonly RecordIssueEventAction $history,
        private readonly AssignIssueAction $assign,
        private readonly WatchIssueAction $watch,
    ) {
    }

    /**
     * @param  User|null  $by  who changed it; taking an issue in progress assigns it to them if nobody has it
     * @param  string|null  $comment  saved as a note marked with the new status
     * @param  bool  $announce  false for bulk changes, which send one summary and one unassign message instead (BulkChangeIssueStatusAction)
     * @param  IgnoreCondition|null  $until  when an ignored issue comes back on its own; none means when it escalates
     */
    public function execute(Issue $issue, IssueStatus $status, ?User $by = null, ?string $comment = null, ?McpToken $token = null, bool $announce = true, ?IgnoreCondition $until = null): Issue
    {
        if ($issue->status === $status) {
            return $issue;
        }

        $from = $issue->status;

        $issue->fill([
            'status' => $status,
            'resolved_at' => $status === IssueStatus::Resolved ? now() : null,
            'ignored_at' => $status === IssueStatus::Ignored ? now() : null,
            'in_progress_at' => $status === IssueStatus::InProgress ? now() : null,
            'regressed_at' => $status->isOpen() ? $issue->regressed_at : null,
            ...ReturnIgnoredIssueAction::cleared(),
        ]);

        if ($status === IssueStatus::Ignored) {
            $until ??= IgnoreCondition::untilEscalation();

            $issue->fill([
                'ignore_until_at' => $until->until,
                'ignore_until_count' => $until->moreOccurrences !== null ? $issue->occurrences_count + $until->moreOccurrences : null,
                'ignore_until_escalation' => $until->escalation,
            ]);
        }

        $issue->save();

        if ($status === IssueStatus::InProgress && $by !== null && $issue->assigned_user_id === null && $by->belongsToTeam($issue->project->team)) {
            $this->assign->execute($issue, $by, $by, $token);
        }

        // A resolved issue needs nobody on it any more; a regression comes back unassigned.
        if ($status === IssueStatus::Resolved && $issue->assigned_user_id !== null) {
            $this->assign->execute($issue, null, $by, $token, $announce, resolved: true);
        }
        $this->history->execute($issue, IssueEventType::StatusChanged, $by, $token, array_filter([
            'from' => $from->value,
            'to' => $status->value,
            'until' => $issue->ignore_until_at?->toIso8601String(),
            'more_occurrences' => $until?->moreOccurrences,
            'escalation' => $issue->ignore_until_escalation ?: null,
        ], fn ($value): bool => $value !== null));

        $note = $comment !== null && trim($comment) !== ''
            ? $this->addNote->execute($issue, $comment, $by, $token, $status)
            : null;

        // Whoever resolves or ignores an issue hears if it comes back.
        if ($by !== null && $token === null && ($status === IssueStatus::Resolved || $status === IssueStatus::Ignored)) {
            $this->watch->execute($issue, $by, WatchReason::Resolved);
        }

        if ($announce) {
            $actor = $token !== null ? __('alerts.messages.via_mcp', ['token' => $token->name]) : ($by->name ?? __('alerts.messages.unknown_author'));
            IssueStatusChanged::dispatch($issue, $status, $actor, $note, $token === null ? $by : null);
        }

        $this->broadcast->issueUpdated($issue->project_id, $issue->uuid);

        return $issue;
    }
}
