<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Notifications;

use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Notifications\Concerns\MarksMaintenance;
use App\Domains\Issues\Actions\DetectEscalationAction;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\SendsMailCards;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An issue alert as mail: new, regressed, escalating, a note, or resolved or ignored.
 * Sent to a rule's mail channel, or to someone watching the issue. Sent synchronously
 * from the queued alert job, so it is not queued itself.
 */
class IssueAlertNotification extends Notification
{
    use MarksMaintenance;
    use SendsMailCards;

    /**
     * @param  list<MailReason>  $reasons  why the reader gets it, the most specific first
     */
    public function __construct(
        public readonly IssueAlert $alert,
        public readonly array $reasons = [],
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->markMaintenance($this->cardMail($this->card(), ($this->reasons[0] ?? null)?->isPersonal() ?? false), $this->alert);
    }

    public function card(): MailCard
    {
        $alert = $this->alert;

        return match (true) {
            $alert->statusChange !== null => $this->statusCard($alert->statusChange),
            $alert->note !== null => $this->noteCard(),
            default => $this->issueCard(),
        };
    }

    private function issueCard(): MailCard
    {
        $alert = $this->alert;
        $issue = $alert->issue;
        $project = $issue->project;
        $name = MailCard::issueName($issue);
        $type = $alert->type;
        $release = $issue->last_release ?? '-';

        $facts = [
            (string) __('mail.issue.facts.release') => ['value' => $release, 'mono' => true],
            (string) __('mail.issue.facts.first_seen') => $issue->first_seen_at->format(__('mail.time')),
            (string) __('mail.issue.facts.occurrences') => number_format((int) $issue->occurrences_count),
            (string) __('mail.issue.facts.users') => number_format((int) $issue->users_count),
        ];
        $lead = null;
        $stat = null;
        $link = ['label' => (string) __('mail.issue.all_open', ['project' => $project->name]), 'url' => route('issues.index', $project)];
        $preheader = trim($this->sentence($issue->message).' '.implode(', ', array_filter([
            $alert->environment,
            trans_choice('mail.issue.occurrences', (int) $issue->occurrences_count),
            (int) $issue->users_count > 0 ? trans_choice('mail.issue.users', $issue->users_count) : null,
        ])));

        if ($type === AlertRuleType::Regression) {
            $resolved = $alert->resolvedAt;
            $actor = $alert->resolvedBy;
            $ago = $resolved?->diffForHumans();
            $lead = (string) match (true) {
                $resolved === null => __('mail.issue.regression.lead_plain'),
                $actor !== null => __('mail.issue.regression.lead', ['actor' => $actor, 'ago' => $ago, 'release' => $release]),
                default => __('mail.issue.regression.lead_unknown', ['ago' => $ago, 'release' => $release]),
            };
            $preheader = $resolved === null ? $lead : __($actor !== null ? 'mail.issue.regression.preheader' : 'mail.issue.regression.preheader_plain', ['actor' => $actor, 'ago' => $ago, 'release' => $release]);
            $facts = [
                (string) __('mail.issue.facts.resolved') => $resolved?->format(__('mail.time')) ?? '-',
                (string) __('mail.issue.facts.back_in') => ['value' => $release, 'mono' => true],
                ...array_slice($facts, 2),
            ];
            $link = ['label' => (string) __('mail.issue.compare_releases'), 'url' => route('releases.index', $project)];
        }

        if ($type === AlertRuleType::Escalation && $alert->burst !== null) {
            $minutes = DetectEscalationAction::WINDOW_MINUTES;
            $stat = ['value' => number_format($alert->burst), 'text' => (string) __('mail.issue.escalation.stat', ['minutes' => $minutes])];
            $preheader = __('mail.issue.escalation.preheader', ['count' => number_format($alert->burst), 'minutes' => $minutes]);
            $facts = [
                (string) __('mail.issue.facts.release') => ['value' => $release, 'mono' => true],
                (string) __('mail.issue.facts.assignee') => $issue->assignee->name ?? __('mail.issue.facts.nobody'),
                ...array_slice($facts, 2),
            ];
        }

        $key = match ($type) {
            AlertRuleType::Regression => 'regression',
            AlertRuleType::Escalation => 'escalation',
            default => 'new_issue',
        };

        return new MailCard(
            subject: MailCard::subject($project->name, __('mail.issue.'.$key.'.subject', ['issue' => $name])),
            preheader: (string) $preheader,
            title: $issue->title,
            titleRef: $issue->number !== null ? $issue->reference() : null,
            kind: (string) __('mail.issue.'.$key.'.kind'),
            tone: $type === AlertRuleType::Regression ? 'amber' : 'red',
            pill: $this->pill(),
            lead: $lead,
            stat: $stat,
            box: ['main' => $issue->message, 'muted' => $issue->file !== null ? $issue->file.($issue->line ? ':'.$issue->line : '') : null],
            facts: $facts,
            quote: $alert->triageSummary !== null ? [
                'label' => (string) ($alert->triageAuthor !== null ? __('mail.issue.latest_note', ['author' => $alert->triageAuthor]) : __('mail.issue.latest_note_unknown')),
                'body' => $alert->triageSummary,
            ] : null,
            button: $this->openButton(),
            link: $link,
            reasons: $this->reasons,
        );
    }

    private function noteCard(): MailCard
    {
        $alert = $this->alert;
        $issue = $alert->issue;
        $note = $alert->note;
        $author = $alert->noteAuthor() ?? (string) __('alerts.messages.unknown_author');
        $ref = MailCard::issueRef($issue);
        $body = $alert->noteBody();

        return new MailCard(
            subject: MailCard::subject($issue->project->name, __('mail.issue.note.subject', ['author' => $author, 'ref' => $ref])),
            preheader: mb_strimwidth($body, 0, 140, '...'),
            title: $issue->title,
            titleRef: $issue->number !== null ? $issue->reference() : null,
            kind: (string) __('mail.issue.note.kind'),
            tone: 'blue',
            pill: $this->pill(),
            lead: $issue->message !== $issue->title ? $issue->message : null,
            comment: [
                'initials' => MailCard::initials($author),
                'name' => $author,
                'what' => (string) __('mail.issue.note.what', ['time' => $note?->created_at?->format(__('mail.clock')) ?? now()->format(__('mail.clock'))]),
                'body' => $body,
            ],
            button: ['label' => (string) ($issue->number !== null ? __('mail.issue.reply', ['ref' => $ref]) : __('mail.issue.open_unnamed')), 'url' => $alert->url],
            reasons: $this->reasons,
        );
    }

    private function statusCard(IssueStatus $status): MailCard
    {
        $alert = $this->alert;
        $issue = $alert->issue;
        $actor = $alert->actor ?? (string) __('alerts.messages.unknown_author');
        $changedAt = $issue->resolved_at ?? $issue->ignored_at ?? now();
        $body = $alert->note !== null ? $alert->noteBody() : null;
        $openFor = $issue->first_seen_at->diffForHumans($changedAt, ['syntax' => CarbonInterface::DIFF_ABSOLUTE]);
        $replace = ['actor' => $actor, 'issue' => MailCard::issueName($issue), 'time' => $changedAt->format(__('mail.clock'))];

        return new MailCard(
            subject: MailCard::subject($issue->project->name, __('mail.issue.status.'.$status->value.'.subject', $replace)),
            preheader: $body !== null ? mb_strimwidth($body, 0, 140, '...') : $openFor.', '.trans_choice('mail.issue.occurrences', (int) $issue->occurrences_count),
            title: $issue->title,
            titleRef: $issue->number !== null ? $issue->reference() : null,
            kind: (string) __('mail.issue.status.'.$status->value.'.kind', $replace),
            tone: $status === IssueStatus::Resolved ? 'green' : 'gray',
            pill: $this->pill(),
            lead: $issue->message !== $issue->title ? $issue->message : null,
            comment: $body !== null ? [
                'initials' => MailCard::initials($actor),
                'name' => $actor,
                'what' => (string) __('mail.issue.status.'.$status->value.'.what', $replace),
                'body' => $body,
            ] : null,
            facts: [
                (string) __('mail.issue.facts.open_for') => $openFor,
                (string) __('mail.issue.facts.last_seen_in') => ['value' => $issue->last_release ?? '-', 'mono' => true],
                (string) __('mail.issue.facts.occurrences') => number_format((int) $issue->occurrences_count),
                (string) __('mail.issue.facts.users') => number_format((int) $issue->users_count),
            ],
            button: $this->openButton(),
            reasons: $this->reasons,
        );
    }

    /** @return array{label: string, url: string} */
    private function openButton(): array
    {
        $issue = $this->alert->issue;

        return ['label' => (string) ($issue->number !== null ? __('mail.issue.open', ['ref' => $issue->reference()]) : __('mail.issue.open_unnamed')), 'url' => $this->alert->url];
    }

    private function pill(): string
    {
        $project = $this->alert->issue->project->name;

        return $this->alert->environment !== null ? (string) __('mail.pill', ['project' => $project, 'environment' => $this->alert->environment]) : $project;
    }

    /**
     * The message as a sentence, so the preheader can carry on after it.
     */
    private function sentence(string $text): string
    {
        $text = trim(mb_strimwidth($text, 0, 140, '...'));

        return $text === '' || preg_match('/[.!?]$/', $text) ? $text : $text.'.';
    }
}
