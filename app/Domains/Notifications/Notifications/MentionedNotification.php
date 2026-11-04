<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\SendsByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A note tags the user.
 */
final class MentionedNotification extends Notification implements ShouldQueue
{
    use SendsByPreference;
    use Queueable;

    public const string KIND = 'mentions';

    /**
     * Its mail is OfferMentionMailsAction, one per note with its other mails.
     */
    public function mailsHere(): bool
    {
        return false;
    }

    /**
     * Its toast is the NoteMentioned broadcast, sent where the event happens.
     */
    public function toastsHere(): bool
    {
        return false;
    }
}
