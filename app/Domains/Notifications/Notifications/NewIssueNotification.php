<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\SendsByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A new issue, a regression or an escalation in a project the user can view.
 */
final class NewIssueNotification extends Notification implements ShouldQueue
{
    use SendsByPreference;
    use Queueable;

    public const string KIND = 'new_issues';
}
