<?php

declare(strict_types=1);

namespace App\Domains\Issues\Listeners;

use App\Domains\Issues\Events\IssueNoteAdded;
use App\Domains\Issues\Events\NoteMentioned;
use App\Domains\Issues\Support\NoteMentions;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Notifications\MentionedNotification;
use Throwable;

/**
 * Every team member a note tags gets a toast if Lumberjack is open, and a browser
 * notification if they turned those on. Their mail goes out
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

        $issue = $note->issue;

        foreach ($users as $user) {
            if ($issue !== null) {
                $user->notify(new MentionedNotification(PushMessage::about(
                    $issue->project,
                    (string) __('notifications.push.mentioned', ['author' => $author, 'title' => $issue->title]),
                    $issue->project->name.' - '.mb_substr($body, 0, 200),
                    route('issues.show', [$issue->project, $issue]),
                    'issue-'.$issue->uuid,
                )));
            }

            if (! $user->notification_preferences->toast('mentions')) {
                continue;
            }

            try {
                broadcast(new NoteMentioned($note, $user->uuid, $author, $body));
            } catch (Throwable $e) {
                // A toast is a nicety; the mail goes out anyway.
                report($e);
            }
        }
    }
}
