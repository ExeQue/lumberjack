<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Notifications;

use App\Domains\Alerts\Contracts\Alert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An alert that is not about an issue (a queue backlog), as mail. Sent synchronously
 * from the queued alert job, so it is not queued itself.
 */
class AlertSummaryNotification extends Notification
{
    public function __construct(
        public readonly Alert $alert,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        ['title' => $title, 'subject' => $subject, 'body' => $body] = $this->alert->summary();
        $mail = (new MailMessage())->subject($title.': '.$subject)->line($subject);

        foreach ([...$body, ...array_map(fn (string $label, string $value): string => $label.': '.$value, array_keys($this->alert->facts()), $this->alert->facts())] as $line) {
            $mail->line($line);
        }

        return $mail->action(__('alerts.open_issue'), $this->alert->url());
    }
}
