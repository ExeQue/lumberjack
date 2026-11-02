<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Actions;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Mcp\Models\McpToken;
use Illuminate\Database\Eloquent\Collection;

/**
 * The open issues this agent has not handled yet, oldest first. Handled means it
 * wrote a note since the issue last surfaced (new, regressed, escalated or back
 * from ignore); without one, an issue comes again on the next call. Issues in
 * progress are someone's already, and resolved or ignored ones are done with.
 */
class ReadTriageInboxAction
{
    public const int LIMIT = 50;

    /**
     * @param  list<int>  $projectIds
     * @return array{issues: Collection<int, Issue>, has_more: bool}
     */
    public function execute(McpToken $token, array $projectIds): array
    {
        $issues = Issue::query()
            ->whereIn('project_id', $projectIds)
            ->whereStatus(IssueStatus::Open)
            ->whereNotHandledBy($token)
            ->orderBySurfaced()
            ->with(['project', 'labels'])
            ->limit(self::LIMIT + 1)
            ->get();

        return [
            'issues' => new Collection($issues->take(self::LIMIT)->values()->all()),
            'has_more' => $issues->count() > self::LIMIT,
        ];
    }
}
