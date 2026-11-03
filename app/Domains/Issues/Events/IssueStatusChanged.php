<?php

declare(strict_types=1);

namespace App\Domains\Issues\Events;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueNote;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone changed an issue's status, with the comment they gave if any. $by is the
 * person, also when they acted through an MCP token; null for nobody in particular.
 */
class IssueStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Issue $issue,
        public readonly IssueStatus $status,
        public readonly string $actor,
        public readonly ?IssueNote $note = null,
        public readonly ?User $by = null,
    ) {
    }
}
