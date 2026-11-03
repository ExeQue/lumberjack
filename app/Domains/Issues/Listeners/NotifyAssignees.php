<?php

declare(strict_types=1);

namespace App\Domains\Issues\Listeners;

use App\Domains\Issues\Actions\NotifyWatchersAction;
use App\Domains\Issues\Data\WatchUpdate;
use App\Domains\Issues\Events\IssueAssignmentChanged;
use App\Domains\Issues\Events\IssuesAssigned;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Notifications\IssueAssignmentNotification;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\MailRecipients;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Whoever was assigned or taken off gets a mail, and a toast if Lumberjack is open: one of
 * each per action, however many issues it touched, as their preferences allow. The
 * issues' watchers hear of the change too, grouped. One action is one event: someone
 * both assigned and watching gets the assignment mail only. Nobody hears about their
 * own change.
 */
class NotifyAssignees
{
    public function __construct(
        private readonly NotifyWatchersAction $watchers,
    ) {
    }

    public function handle(IssuesAssigned $event): void
    {
        $actor = $event->token !== null ? __('alerts.messages.via_mcp', ['token' => $event->token->name]) : ($event->by->name ?? __('alerts.messages.unknown_author'));
        $recipients = new MailRecipients();

        /** @var array<string, array{user: User, issues: list<Issue>, assigned: bool}> $groups */
        $groups = [];

        foreach ($event->changes as $change) {
            foreach ([[$change->to, true], [$change->from, false]] as [$user, $assigned]) {
                if ($user === null || $user->is($event->by)) {
                    continue;
                }

                $key = $user->id.($assigned ? ':in' : ':out');
                $groups[$key] ??= ['user' => $user, 'issues' => [], 'assigned' => $assigned];
                $groups[$key]['issues'][] = $change->issue;
            }
        }

        foreach ($groups as ['user' => $user, 'issues' => $issues, 'assigned' => $assigned]) {
            $notification = new IssueAssignmentNotification(new Collection($issues), $actor, $assigned, $event->resolved && ! $assigned);
            $preferences = $user->notification_preferences;

            if ($preferences->mail('assignments')) {
                $recipients->offer($user->email, MailRecipients::PERSONAL, MailReason::assigned($actor, $notification->project()->name), function (array $reasons) use ($user, $issues, $actor, $assigned, $event): void {
                    $user->notify(new IssueAssignmentNotification(new Collection($issues), $actor, $assigned, $event->resolved && ! $assigned, $reasons));
                });
            }

            if ($preferences->toast('assignments')) {
                try {
                    broadcast(new IssueAssignmentChanged($notification, $user->uuid));
                } catch (Throwable $e) {
                    // A toast is a nicety; the mail still goes out.
                    report($e);
                }
            }
        }

        // Taking a resolved issue off its assignee is part of resolving it, which watchers hear of.
        if (! $event->resolved) {
            $this->watchers->execute(array_map(fn ($change): WatchUpdate => new WatchUpdate($change->issue, WatchUpdate::ASSIGNEE, $actor, [
                'from' => $change->from?->name,
                'to' => $change->to?->name,
                'self' => $change->to !== null && $event->token === null && $change->to->is($event->by) ? '1' : null,
            ]), $event->changes), $event->token === null ? $event->by : null, $recipients);
        }

        $recipients->send();
    }
}
