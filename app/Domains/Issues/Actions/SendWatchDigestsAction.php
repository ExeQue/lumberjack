<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Data\WatchUpdate;
use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueWatcher;
use App\Domains\Issues\Models\IssueWatchUpdate;
use App\Domains\Issues\Notifications\WatchedIssueNotification;
use App\Domains\Shared\Mail\MailReason;
use App\Models\User;
use Throwable;

/**
 * Sends each watcher one mail per issue for the updates that waited out the window.
 *
 * The first update for a watcher and an issue opens a window of WINDOW_MINUTES; the
 * scheduler runs this every minute and mails every group whose first update is that
 * old, with everything that came in meanwhile. ponytail: a pending table and a sweep
 * rather than a delayed job per group: nothing to deduplicate when updates pile up, and
 * a lost job cannot strand them. A window can run up to a minute over.
 */
class SendWatchDigestsAction
{
    public const int WINDOW_MINUTES = 5;

    /**
     * @return int mails sent
     */
    public function execute(): int
    {
        $due = IssueWatchUpdate::query()
            ->select(['user_id', 'issue_id'])
            ->groupBy('user_id', 'issue_id')
            ->havingRaw('MIN(created_at) <= ?', [now()->subMinutes(self::WINDOW_MINUTES)])
            ->get();
        $sent = 0;

        foreach ($due as $group) {
            $rows = IssueWatchUpdate::query()->where('user_id', $group->user_id)->where('issue_id', $group->issue_id)->orderBy('id')->get();
            $user = User::query()->find($group->user_id);
            $issue = Issue::query()->with('project')->find($group->issue_id);
            $watcher = IssueWatcher::query()->where('user_id', $group->user_id)->where('issue_id', $group->issue_id)->whereNull('unwatched_at')->first();

            try {
                if ($user !== null && $issue !== null && $watcher !== null && $user->notification_preferences->mail('watching')) {
                    $others = [];

                    foreach ($rows as $row) {
                        foreach ($row->reasons ?? [] as $stored) {
                            $reason = MailReason::fromArray($stored);

                            if (! in_array($reason, $others)) {
                                $others[] = $reason;
                            }
                        }
                    }

                    $updates = array_values($rows->map(fn (IssueWatchUpdate $row): WatchUpdate => WatchUpdate::fromRow($row, $issue))->all());
                    $user->notifyNow(new WatchedIssueNotification($issue, $user, $updates, $watcher->reason ?? WatchReason::Manual, $others));
                    $sent++;
                }
            } catch (Throwable $e) {
                report($e);
            }

            IssueWatchUpdate::query()->whereKey($rows->modelKeys())->delete();
        }

        return $sent;
    }
}
