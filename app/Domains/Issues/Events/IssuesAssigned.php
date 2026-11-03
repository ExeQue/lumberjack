<?php

declare(strict_types=1);

namespace App\Domains\Issues\Events;

use App\Domains\Issues\Data\IssueAssignment;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Assignees changed on one issue, or on several in one bulk action, so the people
 * concerned hear once per action rather than once per issue.
 */
class IssuesAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<IssueAssignment>  $changes
     * @param  bool  $resolved  the issues were taken off their assignees because they were resolved
     */
    public function __construct(
        public readonly array $changes,
        public readonly ?User $by = null,
        public readonly ?McpToken $token = null,
        public readonly bool $resolved = false,
    ) {
    }
}
