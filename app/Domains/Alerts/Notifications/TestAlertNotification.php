<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Notifications;

use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\SendsMailCards;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestAlertNotification extends Notification
{
    use SendsMailCards;

    public function __construct(
        public readonly NotificationChannel $channel,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->cardMail($this->card(), false);
    }

    public function card(): MailCard
    {
        $name = $this->channel->name;

        return new MailCard(
            subject: __('mail.test.subject', ['channel' => $name]),
            preheader: __('mail.test.preheader', ['channel' => $name]),
            title: (string) __('mail.test.title'),
            tone: 'green',
            lead: (string) __('mail.test.lead', ['channel' => $name]),
            button: ['label' => (string) __('mail.test.open'), 'url' => route('alerts.index')],
            reasons: [MailReason::channel($this->channel)],
        );
    }
}
