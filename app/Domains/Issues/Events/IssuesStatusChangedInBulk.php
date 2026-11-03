<?php

declare(strict_types=1);

namespace App\Domains\Issues\Events;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Projects\Models\Project;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone gave several issues one status at once from the issues list.
 */
class IssuesStatusChangedInBulk implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $issueIds
     */
    public function __construct(
        public readonly Project $project,
        public readonly array $issueIds,
        public readonly IssueStatus $status,
        public readonly string $actor,
        public readonly ?int $byUserId = null,
    ) {
    }
}
