<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Issues\Actions\DetectEscalationAction;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Support\NoteMentions;

final readonly class IssueAlert
{
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
    ) {
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

    public function headline(): string
    {
        // A status change, or a note given with one, reads as what happened to the issue.
        $status = $this->statusChange ?? $this->note?->status;

        if ($status !== null) {
            return __('alerts.messages.status.'.$status->value, [
                'project' => $this->issue->project->name,
                'actor' => $this->actor ?? $this->noteAuthor() ?? __('alerts.messages.unknown_author'),
            ]);
        }

        return __('alerts.messages.'.$this->type->value, [
            'project' => $this->issue->project->name,
            'environment' => $this->environment ?? __('alerts.messages.unknown_environment'),
        ]);
    }
}
