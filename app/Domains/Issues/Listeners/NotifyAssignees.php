<?php

declare(strict_types=1);

namespace App\Domains\Issues\Listeners;

use App\Domains\Issues\Events\IssueAssignmentChanged;
use App\Domains\Issues\Events\IssuesAssigned;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Notifications\IssueAssignmentNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Whoever was assigned or taken off gets a mail, and a toast if Lumberjack is open: one of
 * each per action, however many issues it touched. Nobody hears about their own change.
 */
class NotifyAssignees
{
    public function handle(IssuesAssigned $event): void
    {
        $actor = $event->token !== null ? __('alerts.messages.via_mcp', ['token' => $event->token->name]) : ($event->by->name ?? __('alerts.messages.unknown_author'));

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
            $user->notify($notification);

            try {
                broadcast(new IssueAssignmentChanged($notification, $user->uuid));
            } catch (Throwable $e) {
                // A toast is a nicety; the mail still goes out.
                report($e);
            }
        }
    }
}
