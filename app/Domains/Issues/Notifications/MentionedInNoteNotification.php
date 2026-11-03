<?php

declare(strict_types=1);

namespace App\Domains\Issues\Notifications;

use App\Domains\Issues\Models\IssueNote;
use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\SendsMailCards;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A note tagged the reader. Sent from the note's alert job (SendNoteAlerts), which makes
 * sure nobody gets it and another mail about the same note.
 */
class MentionedInNoteNotification extends Notification
{
    use SendsMailCards;

    /**
     * @param  list<MailReason>  $reasons  why the reader gets it, the mention first
     */
    public function __construct(
        public readonly IssueNote $note,
        public readonly string $author,
        public readonly string $body,
        public readonly array $reasons = [],
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->cardMail($this->card(), true);
    }

    public function card(): MailCard
    {
        /** @var \App\Domains\Issues\Models\Issue $issue */
        $issue = $this->note->issue;
        $project = $issue->project;
        $ref = MailCard::issueRef($issue);

        return new MailCard(
            subject: MailCard::subject($project->name, __('mail.mention.subject', ['author' => $this->author, 'ref' => $ref])),
            preheader: mb_strimwidth($this->body, 0, 140, '...'),
            title: (string) __('mail.mention.title', ['author' => $this->author]),
            titleRef: $ref,
            titleRefLast: true,
            kind: (string) __('mail.mention.kind'),
            tone: 'blue',
            pill: $project->name,
            lead: $issue->number !== null ? ($issue->message !== $issue->title ? $issue->title.': '.$issue->message : $issue->title) : null,
            comment: [
                'initials' => MailCard::initials($this->author),
                'name' => $this->author,
                'what' => (string) __('mail.mention.what', ['time' => ($this->note->created_at ?? now())->format(__('mail.clock'))]),
                'body' => $this->body,
            ],
            button: ['label' => (string) ($issue->number !== null ? __('mail.issue.reply', ['ref' => $ref]) : __('mail.issue.open_unnamed')), 'url' => route('issues.show', [$project, $issue])],
            reasons: $this->reasons !== [] ? $this->reasons : [MailReason::mentioned($this->author, $project->name)],
        );
    }
}
