<?php

declare(strict_types=1);

namespace App\Domains\Issues\Notifications;

use App\Domains\Issues\Data\WatchUpdate;
use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Support\WatchLinks;
use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\SendsMailCards;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What happened to a watched issue within one grouping window, as one mail: "3 updates
 * on DEMO-12" with the timeline, or the one update as its subject. Sent from the
 * scheduled SendWatchDigestsAction, so not queued itself.
 */
class WatchedIssueNotification extends Notification
{
    use SendsMailCards;

    /**
     * @param  list<WatchUpdate>  $updates  oldest first
     * @param  list<MailReason>  $others  other reasons they would have heard of these
     */
    public function __construct(
        public readonly Issue $issue,
        public readonly User $watcher,
        public readonly array $updates,
        public readonly WatchReason $why = WatchReason::Manual,
        public readonly array $others = [],
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
        $issue = $this->issue;
        $project = $issue->project;
        $count = count($this->updates);
        $stop = WatchLinks::stop($issue, $this->watcher);
        $url = route('issues.show', [$project, $issue]);

        return new MailCard(
            subject: MailCard::subject($project->name, $count === 1
                ? $this->updates[0]->headline()
                : __('mail.watching.subject_many', ['count' => $count, 'issue' => MailCard::issueName($issue)])),
            preheader: $count === 1 && $this->updates[0]->body !== null
                ? mb_strimwidth($this->updates[0]->body, 0, 140, '...')
                : implode(', ', array_map(fn (WatchUpdate $u): string => $u->actor.' '.$u->what(), $this->updates)),
            title: $issue->title,
            titleRef: $issue->number !== null ? $issue->reference() : null,
            titleUrl: $url,
            kind: (string) __('mail.watching.kind'),
            tone: 'blue',
            pill: (string) __('mail.watching.pill', ['project' => $project->name]),
            lead: $issue->message !== $issue->title ? $issue->message : null,
            timeline: array_map(fn (WatchUpdate $u): array => [
                'who' => $u->actor,
                'what' => $u->what(),
                'at' => ($u->at ?? now())->format(__('mail.clock')),
                'body' => $u->body,
                'tone' => $u->tone(),
            ], $this->updates),
            button: ['label' => (string) ($issue->number !== null ? __('mail.issue.open', ['ref' => $issue->reference()]) : __('mail.issue.open_unnamed')), 'url' => $url],
            link: ['label' => (string) __('mail.watching.stop'), 'url' => $stop],
            reasons: [MailReason::watching(MailCard::issueRef($issue), $this->why->phrase(), $stop), ...$this->others],
        );
    }
}
