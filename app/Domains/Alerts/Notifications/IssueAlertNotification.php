<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Notifications;

use App\Domains\Alerts\Data\IssueAlert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent synchronously from the queued alert job, so it is not queued itself.
 */
class IssueAlertNotification extends Notification
{
    public function __construct(
        public readonly IssueAlert $alert,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $issue = $this->alert->issue;

        if ($this->alert->statusChange !== null) {
            return (new MailMessage())
                ->subject($this->alert->headline().': '.$issue->title)
                ->line($issue->title)
                ->when($this->alert->note !== null)->line($this->alert->noteBody())
                ->action(__('alerts.open_issue'), $this->alert->url);
        }

        if ($this->alert->note !== null) {
            return (new MailMessage())
                ->subject($this->alert->headline().': '.$issue->title)
                ->line(__('alerts.messages.note_by', ['author' => $this->alert->noteAuthor()]))
                ->line($this->alert->noteBody())
                ->action(__('alerts.open_issue'), $this->alert->url);
        }

        return (new MailMessage())
            ->subject($this->alert->headline().': '.$issue->title)
            ->when($this->alert->burstLine() !== null)->line((string) $this->alert->burstLine())
            ->line($issue->title)
            ->line($issue->message)
            ->when($issue->file !== null)->line(__('alerts.discord.location').': '.$issue->file.':'.$issue->line)
            ->when($this->alert->triageSummary !== null)->line((string) $this->alert->triageSummary)
            ->action(__('alerts.open_issue'), $this->alert->url);
    }
}
