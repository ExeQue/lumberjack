<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueWatcher;
use App\Models\User;

/**
 * Makes someone watch an issue. By choice (Manual) it always does, also after they
 * stopped; by acting on it (assigned, a note, a mention, resolving) only if they never
 * stopped watching it: an explicit unwatch sticks until they choose to watch again.
 */
class WatchIssueAction
{
    public function execute(Issue $issue, User $user, WatchReason $reason = WatchReason::Manual): void
    {
        if ($reason === WatchReason::Manual) {
            IssueWatcher::query()->updateOrCreate(['issue_id' => $issue->id, 'user_id' => $user->id], ['reason' => $reason, 'unwatched_at' => null]);

            return;
        }

        IssueWatcher::query()->insertOrIgnore(['issue_id' => $issue->id, 'user_id' => $user->id, 'reason' => $reason->value, 'created_at' => now()]);
    }
}
