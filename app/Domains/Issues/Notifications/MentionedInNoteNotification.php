<?php

declare(strict_types=1);

namespace App\Domains\Issues\Notifications;

use App\Domains\Issues\Models\IssueNote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MentionedInNoteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly IssueNote $note,
        public readonly string $author,
        public readonly string $body,
    ) {
        $this->onQueue('alerts');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $issue = $this->note->issue;

        return (new MailMessage())
            ->subject(__('issues.mentioned.subject', ['author' => $this->author, 'title' => $issue?->title]))
            ->line(__('issues.mentioned.line', ['author' => $this->author, 'project' => $issue?->project?->name]))
            ->line($this->body)
            ->when($issue !== null)->action(__('alerts.open_issue'), $issue !== null ? route('issues.show', [$issue->project, $issue]) : '');
    }
}
