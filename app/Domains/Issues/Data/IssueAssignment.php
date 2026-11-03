<?php

declare(strict_types=1);

namespace App\Domains\Issues\Data;

use App\Domains\Issues\Models\Issue;
use App\Models\User;

/**
 * One issue's assignee changed, from whom to whom; either side may be nobody.
 */
final readonly class IssueAssignment
{
    public function __construct(
        public Issue $issue,
        public ?User $from,
        public ?User $to,
    ) {
    }
}
