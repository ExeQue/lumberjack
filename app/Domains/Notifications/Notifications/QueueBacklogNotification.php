<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\SendsByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A queue backlog started or cleared.
 */
final class QueueBacklogNotification extends Notification implements ShouldQueue
{
    use SendsByPreference;
    use Queueable;

    public const string KIND = 'queue_backlogs';
}
