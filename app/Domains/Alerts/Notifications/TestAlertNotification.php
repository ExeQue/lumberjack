<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestAlertNotification extends Notification
{
    public function __construct(
        public readonly string $channelName,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('alerts.test_subject'))
            ->line(__('alerts.test_message', ['channel' => $this->channelName]));
    }
}
