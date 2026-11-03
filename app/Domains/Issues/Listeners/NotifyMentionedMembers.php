<?php

declare(strict_types=1);

namespace App\Domains\Issues\Listeners;

use App\Domains\Issues\Events\IssueNoteAdded;
use App\Domains\Issues\Events\NoteMentioned;
use App\Domains\Issues\Support\NoteMentions;
use Throwable;

/**
 * Every team member a note tags gets a toast if Lumberjack is open. Their mail goes out
 * with the note's other mails (SendNoteAlerts), so nobody gets two about one note.
 */
class NotifyMentionedMembers
{
    public function __construct(
        private readonly NoteMentions $mentions,
    ) {
    }

    public function handle(IssueNoteAdded $event): void
    {
        $note = $event->note->loadMissing(['issue.project.team', 'user', 'mcpToken']);
        $users = $this->mentions->users($note);

        if ($users->isEmpty()) {
            return;
        }

        $author = $this->mentions->author($note);
        $body = $this->mentions->render($note->body, $note->issue?->project?->team);

        foreach ($users as $user) {
            try {
                broadcast(new NoteMentioned($note, $user->uuid, $author, $body));
            } catch (Throwable $e) {
                // A toast is a nicety; the mail goes out anyway.
                report($e);
            }
        }
    }
}
