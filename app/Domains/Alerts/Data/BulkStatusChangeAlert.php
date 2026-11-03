<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;

/**
 * Someone resolved or ignored several issues at once: one message for all of them.
 */
final readonly class BulkStatusChangeAlert implements Alert
{
    /** Issues named in the message; the rest are counted. Webhooks get them all. */
    public const int LISTED = 10;

    /**
     * @param  list<Issue>  $issues
     * @param  list<string>  $environments  the issues' environments
     */
    public function __construct(
        public Project $project,
        public IssueStatus $status,
        public string $actor,
        public array $issues,
        public array $environments,
        public string $url,
    ) {
    }

    public function url(): string
    {
        return $this->url;
    }

    public function headline(): string
    {
        return trans_choice('alerts.messages.bulk_status.'.$this->status->value, count($this->issues), ['actor' => $this->actor, 'project' => $this->project->name]);
    }

    public function summary(): array
    {
        $listed = array_slice($this->issues, 0, self::LISTED);
        $more = count($this->issues) - count($listed);
        $moreLine = $more > 0 ? (string) __('alerts.bulk_status.more', ['count' => $more]) : null;

        return [
            'title' => $this->headline(),
            'subject' => implode(', ', array_filter([...array_map(fn (Issue $issue): string => $issue->number !== null ? $issue->reference() : $issue->title, $listed), $moreLine])),
            'body' => array_values(array_filter([
                ...array_map(fn (Issue $issue): string => ($issue->number !== null ? $issue->reference().' · ' : '').$issue->title, $listed),
                $moreLine,
            ])),
        ];
    }

    public function facts(): array
    {
        return [
            (string) __('alerts.bulk_status.facts.status') => $this->status->label(),
            (string) __('alerts.bulk_status.facts.issues') => number_format(count($this->issues)),
            (string) __('alerts.bulk_status.facts.environment') => $this->environments !== [] ? implode(', ', $this->environments) : '-',
        ];
    }

    public function urgency(): string
    {
        return 'low';
    }
}
