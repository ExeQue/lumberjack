<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Actions;

use App\Domains\Issues\Models\IssueNote;
use App\Domains\Mcp\Models\McpToken;
use Illuminate\Database\Eloquent\Collection;

/**
 * Notes that tagged this token and that it has not answered: no note of its own
 * on that issue since. On the projects the token covers, oldest first.
 */
class ReadMentionsAction
{
    public const int LIMIT = 50;

    /**
     * @param  list<int>  $projectIds
     * @return Collection<int, IssueNote>
     */
    public function execute(McpToken $token, array $projectIds): Collection
    {
        return IssueNote::query()
            ->with(['issue.project', 'user', 'mcpToken'])
            ->whereHas('issue', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->where('body', 'like', '%<@mcp:'.$token->id.'>%')
            // An agent tagging itself is not someone asking it for something.
            ->where(fn ($q) => $q->whereNull('mcp_token_id')->orWhere('mcp_token_id', '!=', $token->id))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('issue_notes as answer')
                ->whereColumn('answer.issue_id', 'issue_notes.issue_id')
                ->where('answer.mcp_token_id', $token->id)
                ->whereColumn('answer.id', '>', 'issue_notes.id'))
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }
}
