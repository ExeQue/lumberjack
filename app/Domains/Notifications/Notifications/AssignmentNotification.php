<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\SendsByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The user was assigned to issues or taken off them.
 */
final class AssignmentNotification extends Notification implements ShouldQueue
{
    use SendsByPreference;
    use Queueable;

    public const string KIND = 'assignments';

    /**
     * Its mail is IssueAssignmentNotification, offered with the action's other mails.
     */
    public function mailsHere(): bool
    {
        return false;
    }

    /**
     * Its toast is the IssueAssignmentChanged broadcast, sent where the event happens.
     */
    public function toastsHere(): bool
    {
        return false;
    }
}
