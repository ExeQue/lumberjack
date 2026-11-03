<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Alerts\Notifications\IssueAlertNotification;
use App\Domains\Issues\Data\WatchUpdate;
use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Events\WatchedIssueUpdated;
use App\Domains\Issues\Models\IssueWatchUpdate;
use App\Domains\Issues\Support\WatchLinks;
use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\MailRecipients;
use App\Models\User;
use Throwable;

/**
 * Tells the watchers of issues what just happened to them, as part of one event: a toast
 * right away, and a mail offered to the event's MailRecipients. A regression or an
 * escalation is mailed at once; anything else waits as an IssueWatchUpdate for the
 * watcher's grouped mail (SendWatchDigestsAction). Nobody hears about their own action.
 */
class NotifyWatchersAction
{
    /**
     * @param  list<WatchUpdate>  $updates  one per issue
     * @param  User|null  $actor  who did it; null for what happened by itself or through an agent
     * @param  int  $priority  how specific the watcher's mail is against the event's others
     */
    public function execute(array $updates, ?User $actor, MailRecipients $recipients, int $priority = MailRecipients::WATCHER): void
    {
        /** @var array<int, array{user: User, items: list<array{0: WatchUpdate, 1: WatchReason}>}> $watchers */
        $watchers = [];

        foreach ($updates as $update) {
            foreach ($update->issue->watchers()->get() as $user) {
                if ($actor !== null && $user->is($actor)) {
                    continue;
                }

                $watchers[$user->id] ??= ['user' => $user, 'items' => []];
                $watchers[$user->id]['items'][] = [$update, WatchReason::tryFrom((string) $user->getAttribute('pivot')?->getAttribute('reason')) ?? WatchReason::Manual];
            }
        }

        foreach ($watchers as ['user' => $user, 'items' => $items]) {
            $preferences = $user->notification_preferences;

            // Someone mentioned or assigned in this event already gets a toast for it.
            if ($preferences->toast('watching') && ! $recipients->has($user->email, MailRecipients::PERSONAL)) {
                foreach ($items as [$update]) {
                    $this->toast($user, $update);
                }
            }

            if (! $preferences->mail('watching')) {
                continue;
            }

            [$first, $why] = $items[0];
            $reason = count($items) === 1
                ? MailReason::watching(MailCard::issueRef($first->issue), $why->phrase(), WatchLinks::stop($first->issue, $user))
                : MailReason::watching(implode(', ', array_map(fn (array $item): string => MailCard::issueRef($item[0]->issue), $items)));

            $recipients->offer($user->email, $priority, $reason, function (array $reasons) use ($user, $items): void {
                $others = array_values(array_filter($reasons, fn (MailReason $r): bool => $r->kind !== MailReason::WATCHING));

                foreach ($items as [$update]) {
                    if ($update->immediate() && $update->alert !== null) {
                        $user->notifyNow(new IssueAlertNotification($update->alert, $reasons));

                        continue;
                    }

                    IssueWatchUpdate::query()->create([
                        'issue_id' => $update->issue->id,
                        'user_id' => $user->id,
                        'kind' => $update->kind,
                        'actor' => $update->actor,
                        'data' => $update->data,
                        'body' => $update->body,
                        'reasons' => array_map(fn (MailReason $r): array => $r->toArray(), $others),
                        'created_at' => now(),
                    ]);
                }
            });
        }
    }

    private function toast(User $user, WatchUpdate $update): void
    {
        try {
            broadcast(new WatchedIssueUpdated($user->uuid, $update->issue->project->name, $update->headline(), route('issues.show', [$update->issue->project, $update->issue])));
        } catch (Throwable $e) {
            // A toast is a nicety; the mail goes out anyway.
            report($e);
        }
    }
}
