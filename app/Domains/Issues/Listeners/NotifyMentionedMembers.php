<?php

declare(strict_types=1);

namespace App\Domains\Issues\Listeners;

use App\Domains\Issues\Events\IssueNoteAdded;
use App\Domains\Issues\Events\NoteMentioned;
use App\Domains\Issues\Notifications\MentionedInNoteNotification;
use App\Domains\Issues\Support\NoteMentions;
use Throwable;

/**
 * Every team member a note tags gets a mail, and a toast if Lumberjack is open.
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

        $author = $note->mcpToken !== null ? __('alerts.messages.via_mcp', ['token' => $note->mcpToken->name]) : ($note->user->name ?? __('alerts.messages.unknown_author'));
        $body = $this->mentions->render($note->body, $note->issue?->project?->team);

        foreach ($users as $user) {
            $user->notify(new MentionedInNoteNotification($note, $author, $body));

            try {
                broadcast(new NoteMentioned($note, $user->id, $author, $body));
            } catch (Throwable $e) {
                // A toast is a nicety; the mail still goes out.
                report($e);
            }
        }
    }
}
