<?php

declare(strict_types=1);

namespace App\Domains\Issues\Data;

use App\Domains\Alerts\Data\IssueAlert;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueWatchUpdate;
use App\Domains\Shared\Mail\MailCard;
use Carbon\CarbonImmutable;

/**
 * Something that happened to an issue its watchers hear about. A regression or an
 * escalation goes out right away with its alert; a note, a status or an assignee change
 * waits for the watcher's grouped mail.
 *
 * Data: status => the new status; assignee => from and to (names, or null), self (the
 * actor took it).
 */
final readonly class WatchUpdate
{
    public const string NOTE = 'note';

    public const string STATUS = 'status';

    public const string ASSIGNEE = 'assignee';

    public const string REGRESSION = 'regression';

    public const string ESCALATION = 'escalation';

    /**
     * @param  array<string, string|null>  $data
     */
    public function __construct(
        public Issue $issue,
        public string $kind,
        public string $actor,
        public array $data = [],
        public ?string $body = null,
        public ?IssueAlert $alert = null,
        public ?CarbonImmutable $at = null,
    ) {
    }

    public static function fromRow(IssueWatchUpdate $row, Issue $issue): self
    {
        return new self($issue, $row->kind, $row->actor, $row->data ?? [], $row->body, at: $row->created_at);
    }

    public function immediate(): bool
    {
        return $this->kind === self::REGRESSION || $this->kind === self::ESCALATION;
    }

    /**
     * "took it over from Sofie", after the actor's name.
     */
    public function what(): string
    {
        $data = $this->data;

        return (string) match ($this->kind) {
            self::STATUS => __('mail.watching.what.'.($data['status'] ?? 'open')),
            self::ASSIGNEE => match (true) {
                ($data['to'] ?? null) !== null && ($data['self'] ?? null) !== null => ($data['from'] ?? null) !== null
                    ? __('mail.watching.what.took_from', ['from' => $data['from']])
                    : __('mail.watching.what.took'),
                ($data['to'] ?? null) !== null => ($data['from'] ?? null) !== null
                    ? __('mail.watching.what.reassigned', ['from' => $data['from'], 'to' => $data['to']])
                    : __('mail.watching.what.assigned', ['to' => $data['to']]),
                default => __('mail.watching.what.unassigned', ['from' => $data['from'] ?? '']),
            },
            self::REGRESSION => __('mail.watching.what.regression'),
            self::ESCALATION => __('mail.watching.what.escalation'),
            default => __('mail.watching.what.note'),
        };
    }

    /**
     * The whole update as a sentence, for a one-update subject and for the toast:
     * "Sofie wrote a note on DEMO-12".
     */
    public function headline(): string
    {
        $issue = $this->issue;
        $replace = ['actor' => $this->actor, 'ref' => MailCard::issueRef($issue), 'issue' => MailCard::issueName($issue), 'from' => $this->data['from'] ?? '', 'to' => $this->data['to'] ?? ''];

        return (string) match ($this->kind) {
            self::STATUS => __('mail.watching.subject.'.($this->data['status'] ?? 'open'), $replace),
            self::ASSIGNEE => match (true) {
                ($this->data['to'] ?? null) !== null && ($this->data['self'] ?? null) !== null => __('mail.watching.subject.took', $replace),
                ($this->data['to'] ?? null) !== null => __('mail.watching.subject.assigned', $replace),
                default => __('mail.watching.subject.unassigned', $replace),
            },
            self::REGRESSION => __('mail.issue.regression.subject', $replace),
            self::ESCALATION => __('mail.issue.escalation.subject', $replace),
            default => __('mail.watching.subject.note', $replace),
        };
    }

    /**
     * The timeline dot: green for resolved, amber and red for a regression or an escalation.
     */
    public function tone(): string
    {
        return match (true) {
            $this->kind === self::STATUS && ($this->data['status'] ?? null) === 'resolved' => 'green',
            $this->kind === self::STATUS && ($this->data['status'] ?? null) === 'ignored' => 'gray',
            $this->kind === self::REGRESSION => 'amber',
            $this->kind === self::ESCALATION => 'red',
            default => 'blue',
        };
    }
}
