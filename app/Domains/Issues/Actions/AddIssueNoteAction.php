<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Events\IssueNoteAdded;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Support\NoteMentions;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;

class AddIssueNoteAction
{
    public function __construct(
        private readonly BroadcastProjectChangeAction $broadcast,
        private readonly WatchIssueAction $watch,
        private readonly NoteMentions $mentions,
    ) {
    }

    /**
     * @param  IssueStatus|null  $status  the status the issue was given with this note
     */
    public function execute(Issue $issue, string $body, ?User $user = null, ?McpToken $token = null, ?IssueStatus $status = null): IssueNote
    {
        $note = $issue->notes()->create([
            'body' => mb_substr(trim($body), 0, 20_000),
            'user_id' => $user !== null ? $user->id : $token?->user_id,
            'mcp_token_id' => $token?->id,
            'status' => $status,
        ]);

        // Who writes a note, or is tagged in one, hears what happens next. An agent's note
        // does not make its token's owner a watcher.
        if ($user !== null) {
            $this->watch->execute($issue, $user, WatchReason::Noted);
        }

        foreach ($this->mentions->users($note) as $mentioned) {
            $this->watch->execute($issue, $mentioned, WatchReason::Mentioned);
        }

        $this->broadcast->issueUpdated($issue->project_id, $issue->uuid);
        IssueNoteAdded::dispatch($note);

        return $note;
    }
}
