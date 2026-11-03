<?php

declare(strict_types=1);

namespace App\Domains\Issues\Notifications;

use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
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

    /** Issues named in the mail; the rest are counted. */
    public const int LISTED = 10;

    /**
     * @param  Collection<int, Issue>  $issues  all in one project
     * @param  bool  $assigned  true for assigned to them, false for taken off
     * @param  bool  $resolved  taken off because they were resolved
     */
    public function __construct(
        public readonly Collection $issues,
        public readonly string $actor,
        public readonly bool $assigned,
        public readonly bool $resolved = false,
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
        $mail = (new MailMessage())
            ->subject($this->headline())
            ->line(__('issues.assignment.line', ['project' => $this->project()->name]));

        foreach ($this->issues->take(self::LISTED) as $issue) {
            $mail->line(($issue->number !== null ? $issue->reference().' · ' : '').$issue->title);
        }

        return $mail
            ->when($this->issues->count() > self::LISTED)->line(__('issues.assignment.more', ['count' => $this->issues->count() - self::LISTED]))
            ->action(__('alerts.open_issue'), $this->url());
    }
}
