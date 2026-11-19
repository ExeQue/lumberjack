<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\SendsByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An AI budget filling up, or an AI key whose calls fail too often.
 */
final class AiAlertNotification extends Notification implements ShouldQueue
{
    use SendsByPreference;
    use Queueable;

    public const string KIND = 'ai';
}
