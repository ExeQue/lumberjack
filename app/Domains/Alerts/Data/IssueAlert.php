<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Data\Concerns\LabelsMaintenance;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Issues\Actions\DetectEscalationAction;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Support\NoteMentions;
use Carbon\CarbonImmutable;
use Override;

/**
 * For a regression, $resolvedBy and $resolvedAt say who resolved it last and when.
 */
final readonly class IssueAlert implements Alert
{
    use LabelsMaintenance;

    public function __construct(
        public Issue $issue,
        public AlertRuleType $type,
        public ?string $environment,
        public string $url,
        public ?string $triageSummary = null,
        public ?IssueNote $note = null,
        public ?int $burst = null,
        public ?IssueStatus $statusChange = null,
        public ?string $actor = null,
        public ?string $triageAuthor = null,
        public ?string $resolvedBy = null,
        public ?CarbonImmutable $resolvedAt = null,
    ) {
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * The note's text, with tags of team members written as @Name.
     */
    public function noteBody(): string
    {
        return $this->note === null ? '' : app(NoteMentions::class)->render($this->note->body, $this->issue->project->team);
    }

    /**
     * Who wrote the note: a person, or the MCP token an agent used.
     */
    public function noteAuthor(): ?string
    {
        if ($this->note === null) {
            return null;
        }

        if ($this->note->mcpToken !== null) {
            return __('alerts.messages.via_mcp', ['token' => $this->note->mcpToken->name]);
        }

        return $this->note->user->name ?? __('alerts.messages.unknown_author');
    }

    /**
     * "42 occurrences in 5 minutes", for an escalation.
     */
    public function burstLine(): ?string
    {
        return $this->burst === null ? null : trans_choice('alerts.messages.burst', $this->burst, ['minutes' => DetectEscalationAction::WINDOW_MINUTES]);
    }

    /**
     * The alert as plain parts each channel formats its own way: a title (what happened),
     * the issue (DEMO-42 · title) and lines of detail (the burst, the message, a note).
     *
     * @return array{title: string, subject: string, body: list<string>}
     */
    public function summary(): array
    {
        $issue = $this->issue;
        $subject = ($issue->number !== null ? $issue->reference().' · ' : '').$issue->title;

        $body = match (true) {
            $this->statusChange !== null => array_filter([$this->note !== null ? $this->noteBody() : null]),
            $this->note !== null => [__('alerts.messages.note_by', ['author' => $this->noteAuthor() ?? __('alerts.messages.unknown_author')]), $this->noteBody()],
            default => array_filter([$this->burstLine(), $issue->message, $this->triageSummary]),
        };

        return ['title' => $this->headline(), 'subject' => $subject, 'body' => array_values(array_map('strval', $body))];
    }

    /**
     * The facts every chat message lists under the text, label => value: where it
     * happened, the release and the occurrence count. None for notes and status changes.
     *
     * @return array<string, string>
     */
    public function facts(): array
    {
        if ($this->statusChange !== null || $this->note !== null) {
            return [];
        }

        $issue = $this->issue;

        return array_filter([
            (string) __('alerts.discord.location') => $issue->file !== null ? $issue->file.($issue->line ? ':'.$issue->line : '') : null,
            (string) __('alerts.discord.release') => $issue->last_release ?? '-',
            (string) __('alerts.discord.occurrences') => (string) $issue->occurrences_count,
            (string) __('alerts.discord.users') => $issue->users_count > 0 ? (string) $issue->users_count : null,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * How loudly a phone should announce it: an escalation is urgent, a note or a status
     * change is quiet, a new or returning issue is normal.
     */
    public function urgency(): string
    {
        return match (true) {
            $this->type === AlertRuleType::Escalation && $this->note === null && $this->statusChange === null => 'high',
            $this->note !== null || $this->statusChange !== null => 'low',
            default => 'normal',
        };
    }

    public function headline(): string
    {
        // A status change, or a note given with one, reads as what happened to the issue.
        $status = $this->statusChange ?? $this->note?->status;

        if ($status !== null) {
            return $this->marked((string) __('alerts.messages.status.'.$status->value, [
                'project' => $this->issue->project->name,
                'actor' => $this->actor ?? $this->noteAuthor() ?? __('alerts.messages.unknown_author'),
            ]));
        }

        return $this->marked((string) __('alerts.messages.'.$this->type->value, [
            'project' => $this->issue->project->name,
            'environment' => $this->environment ?? __('alerts.messages.unknown_environment'),
        ]));
    }

    #[Override]
    protected function maintenanceScope(): array
    {
        return [$this->issue->project_id, $this->environment];
    }
}
