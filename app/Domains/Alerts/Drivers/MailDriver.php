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
use Illuminate\Support\Facades\Notification;
use Override;

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
        Notification::route('mail', (string) $channel->config['address'])->notifyNow($alert instanceof IssueAlert ? new IssueAlertNotification($alert) : new AlertSummaryNotification($alert));
    }

    #[Override]
    public function sendTest(NotificationChannel $channel): void
    {
        Notification::route('mail', (string) $channel->config['address'])->notifyNow(new TestAlertNotification($channel->name));
    }
}
