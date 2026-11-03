<?php

declare(strict_types=1);

namespace App\Domains\Issues\Eloquent\Builders;

use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Shared\Support\LikePattern;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Issue>
 */
class IssueBuilder extends Builder
{
    public function whereStatus(IssueStatus $status): self
    {
        return $this->where('status', $status->value);
    }

    public function orWhereStatus(IssueStatus $status): self
    {
        return $this->orWhere('status', $status->value);
    }

    /**
     * Open or in progress.
     */
    public function whereOpen(): self
    {
        return $this->whereIn('status', [IssueStatus::Open->value, IssueStatus::InProgress->value]);
    }

    public function orWhereOpen(): self
    {
        return $this->orWhereIn('status', [IssueStatus::Open->value, IssueStatus::InProgress->value]);
    }

    public function whereWatchedBy(User $user): self
    {
        return $this->whereHas('watchers', fn ($q) => $q->whereKey($user->id));
    }

    public function orWhereWatchedBy(User $user): self
    {
        return $this->orWhereHas('watchers', fn ($q) => $q->whereKey($user->id));
    }

    public function whereSource(IssueSource $source): self
    {
        return $this->where('source', $source->value);
    }

    public function orWhereSource(IssueSource $source): self
    {
        return $this->orWhere('source', $source->value);
    }

    public function whereSeenSince(CarbonInterface $since): self
    {
        return $this->where('last_seen_at', '>=', $since);
    }

    public function orWhereSeenSince(CarbonInterface $since): self
    {
        return $this->orWhere('last_seen_at', '>=', $since);
    }

    /**
     * Title or message, or the issue's number: "42", "#42" or "DEMO-42".
     */
    public function whereSearch(string $term): self
    {
        $number = preg_match('/^(?:[a-z0-9]+-|#)?(\d+)$/i', trim($term), $m) === 1 ? (int) $m[1] : null;

        return $this->where(fn (self $query) => $query
            ->where('title', 'ilike', LikePattern::contains($term))
            ->orWhere('message', 'ilike', LikePattern::contains($term))
            ->when($number !== null)->orWhere('number', $number));
    }

    public function orWhereSearch(string $term): self
    {
        return $this->orWhere(fn (self $query) => $query->whereSearch($term));
    }

    /**
     * Issues that surfaced (created or regressed) after a cursor position.
     */
    public function whereHasLabel(string $name): self
    {
        return $this->whereHas('labels', fn ($q) => $q->where('name', $name));
    }

    public function orWhereHasLabel(string $name): self
    {
        return $this->orWhereHas('labels', fn ($q) => $q->where('name', $name));
    }

    /**
     * Not handled by this agent since it last surfaced: no note from its token since then.
     */
    public function whereNotHandledBy(McpToken $token): self
    {
        return $this->whereNotExists(fn ($q) => $q->selectRaw('1')->from('issue_notes')
            ->whereColumn('issue_notes.issue_id', 'issues.id')
            ->where('issue_notes.mcp_token_id', $token->id)
            ->whereColumn('issue_notes.created_at', '>=', 'issues.surfaced_at'));
    }

    public function orWhereNotHandledBy(McpToken $token): self
    {
        return $this->orWhereNotExists(fn ($q) => $q->selectRaw('1')->from('issue_notes')
            ->whereColumn('issue_notes.issue_id', 'issues.id')
            ->where('issue_notes.mcp_token_id', $token->id)
            ->whereColumn('issue_notes.created_at', '>=', 'issues.surfaced_at'));
    }

    public function orderBySurfaced(): self
    {
        return $this->orderBy('surfaced_at')->orderBy('id');
    }
}
