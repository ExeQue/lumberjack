<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Notifications\MentionedInNoteNotification;
use App\Domains\Issues\Support\NoteMentions;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\MailRecipients;

/**
 * Offers each member a note tags their mention mail, as part of the note's event: it
 * outranks the note rule's channel mail and any watching mail to the same address.
 */
class OfferMentionMailsAction
{
    public function __construct(
        private readonly NoteMentions $mentions,
    ) {
    }

    public function execute(IssueNote $note, MailRecipients $recipients): void
    {
        $note->loadMissing(['issue.project.team', 'user', 'mcpToken']);
        $project = $note->issue?->project;

        if ($project === null) {
            return;
        }

        $author = $this->mentions->author($note);
        $body = $this->mentions->render($note->body, $project->team);

        foreach ($this->mentions->users($note) as $user) {
            if (! $user->notification_preferences->mail('mentions')) {
                continue;
            }

            $recipients->offer($user->email, MailRecipients::PERSONAL, MailReason::mentioned($author, $project->name), function (array $reasons) use ($user, $note, $author, $body): void {
                $user->notifyNow(new MentionedInNoteNotification($note, $author, $body, $reasons));
            });
        }
    }
}
