<?php

declare(strict_types=1);

namespace App\Domains\Issues\Notifications;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\SendsMailCards;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Someone assigned a member to issues, or took them off, in one action: one mail for all.
 */
class IssueAssignmentNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SendsMailCards;

    /** Issues named in the mail; the rest are counted. */
    public const int LISTED = 10;

    /**
     * @param  Collection<int, Issue>  $issues  all in one project
     * @param  bool  $assigned  true for assigned to them, false for taken off
     * @param  bool  $resolved  taken off because they were resolved
     * @param  list<MailReason>  $reasons  why the reader gets it, the assignment first
     */
    public function __construct(
        public readonly Collection $issues,
        public readonly string $actor,
        public readonly bool $assigned,
        public readonly bool $resolved = false,
        public readonly array $reasons = [],
    ) {
        $this->onQueue('alerts');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function project(): Project
    {
        /** @var Issue $issue */
        $issue = $this->issues->first();

        return $issue->project;
    }

    public function headline(): string
    {
        $key = 'issues.assignment.'.($this->assigned ? 'assigned' : ($this->resolved ? 'resolved' : 'unassigned'));
        $count = $this->issues->count();

        return trans_choice($key, $count, ['actor' => $this->actor, 'title' => $this->issues->first()?->title, 'count' => $count, 'project' => $this->project()->name]);
    }

    public function url(): string
    {
        if ($this->issues->count() === 1) {
            return route('issues.show', [$this->project(), $this->issues->first()]);
        }

        return route('issues.index', ['project' => $this->project(), ...($this->assigned ? ['assigned' => 'me'] : []), ...($this->resolved ? ['status' => 'resolved'] : [])]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->cardMail($this->card(), true);
    }

    public function card(): MailCard
    {
        $project = $this->project();
        $count = $this->issues->count();
        $key = $this->assigned ? 'assigned' : ($this->resolved ? 'resolved' : 'unassigned');
        /** @var Issue $first */
        $first = $this->issues->first();
        $replace = ['actor' => $this->actor, 'issue' => MailCard::issueName($first), 'count' => $count];
        $listed = $this->issues->take(self::LISTED);

        return new MailCard(
            subject: MailCard::subject($project->name, trans_choice('mail.assignment.subject.'.$key, $count, $replace)),
            preheader: $listed->map(fn (Issue $issue): string => MailCard::issueRef($issue))->implode(', '),
            title: trans_choice('mail.assignment.title.'.$key, $count, $replace),
            kind: (string) __('mail.assignment.kind.'.$key),
            tone: $this->resolved ? 'green' : 'blue',
            pill: $project->name,
            rows: array_values($listed->map(fn (Issue $issue): array => [
                'ref' => $issue->number !== null ? $issue->reference() : null,
                'title' => $issue->title,
                'url' => route('issues.show', [$project, $issue]),
                'state' => $issue->isEscalating() ? (string) __('mail.assignment.escalating') : $issue->status->label(),
                'tone' => match (true) {
                    $issue->isEscalating() => 'red',
                    $issue->status === IssueStatus::Resolved => 'green',
                    $issue->status === IssueStatus::InProgress => 'blue',
                    default => 'gray',
                },
            ])->all()),
            more: $count > self::LISTED ? (string) __('mail.assignment.more', ['count' => $count - self::LISTED]) : null,
            button: ['label' => (string) match (true) {
                $count === 1 => $first->number !== null ? __('mail.issue.open', ['ref' => $first->reference()]) : __('mail.issue.open_unnamed'),
                $this->assigned => __('mail.assignment.see_yours'),
                $this->resolved => __('mail.assignment.see_resolved'),
                default => __('mail.assignment.see_all'),
            }, 'url' => $this->url()],
            reasons: $this->reasons !== [] ? $this->reasons : [MailReason::assigned($this->actor, $project->name)],
        );
    }
}
