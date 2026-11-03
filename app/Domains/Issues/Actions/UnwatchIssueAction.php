<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueWatcher;
use App\Domains\Issues\Models\IssueWatchUpdate;
use App\Models\User;

/**
 * Stops someone watching an issue, for good: the row stays, marked, so acting on the
 * issue later does not make them a watcher again. Updates waiting for their grouped
 * mail are dropped.
 */
class UnwatchIssueAction
{
    public function execute(Issue $issue, User $user): void
    {
        $watcher = IssueWatcher::query()->firstOrNew(['issue_id' => $issue->id, 'user_id' => $user->id], ['reason' => WatchReason::Manual]);
        $watcher->unwatched_at ??= now();
        $watcher->save();

        IssueWatchUpdate::query()->where('issue_id', $issue->id)->where('user_id', $user->id)->delete();
    }
}
