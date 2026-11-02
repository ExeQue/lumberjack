<?php

declare(strict_types=1);

namespace App\Domains\Issues\Events;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueNote;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone resolved or ignored an issue, with the comment they gave if any.
 */
class IssueStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Issue $issue,
        public readonly IssueStatus $status,
        public readonly string $actor,
        public readonly ?IssueNote $note = null,
    ) {
    }
}
