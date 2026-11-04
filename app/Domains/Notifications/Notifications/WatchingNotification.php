<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\SendsByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Updates on an issue the user watches.
 */
final class WatchingNotification extends Notification implements ShouldQueue
{
    use SendsByPreference;
    use Queueable;

    public const string KIND = 'watching';

    /**
     * Its mail is its grouped digest (NotifyWatchersAction, SendWatchDigestsAction).
     */
    public function mailsHere(): bool
    {
        return false;
    }

    /**
     * Its toast is the WatchedIssueUpdated broadcast, sent where the event happens.
     */
    public function toastsHere(): bool
    {
        return false;
    }
}
