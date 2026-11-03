<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Drivers;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Contracts\ChannelDriver;
use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Notifications\AlertSummaryNotification;
use App\Domains\Alerts\Notifications\IssueAlertNotification;
use App\Domains\Alerts\Notifications\TestAlertNotification;
use App\Domains\Shared\Mail\MailReason;
use Illuminate\Support\Facades\Notification;
use Override;

/**
 * Alerts as mail. The send actions offer these to the event's MailRecipients instead of
 * calling send(), so an address gets one mail per event; see sendWithReasons().
 */
class MailDriver implements ChannelDriver
{
    #[Override]
    public function rules(): array
    {
        return [
            'address' => ['required', 'email'],
        ];
    }

    #[Override]
    public function send(NotificationChannel $channel, Alert $alert): void
    {
        $this->sendWithReasons($channel, $alert, [MailReason::channel($channel)]);
    }

    /**
     * @param  list<MailReason>  $reasons  every reason the address gets it, for the footer
     */
    public function sendWithReasons(NotificationChannel $channel, Alert $alert, array $reasons): void
    {
        Notification::route('mail', self::address($channel))->notifyNow($alert instanceof IssueAlert
            ? new IssueAlertNotification($alert, $reasons)
            : new AlertSummaryNotification($alert, $reasons));
    }

    #[Override]
    public function sendTest(NotificationChannel $channel): void
    {
        Notification::route('mail', self::address($channel))->notifyNow(new TestAlertNotification($channel));
    }

    public static function address(NotificationChannel $channel): string
    {
        return trim((string) ($channel->config['address'] ?? ''));
    }
}
