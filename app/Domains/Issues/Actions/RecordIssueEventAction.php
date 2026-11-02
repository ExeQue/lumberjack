<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueEvent;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Adds a line to an issue's history, with who did it: a person, an agent's
 * MCP token, or nobody for what Lumberjack itself saw happen.
 */
class RecordIssueEventAction
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function execute(Issue $issue, IssueEventType $type, ?User $by = null, ?McpToken $token = null, ?array $data = null, ?CarbonInterface $at = null): IssueEvent
    {
        return IssueEvent::query()->create([
            'issue_id' => $issue->id,
            'type' => $type,
            'user_id' => $token === null ? $by?->id : null,
            'mcp_token_id' => $token?->id,
            'data' => $data,
            'occurred_at' => $at ?? now(),
        ]);
    }
}
