<?php

declare(strict_types=1);

namespace App\Domains\Issues\Events;

use App\Domains\Issues\Models\Issue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class IssueEscalated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Issue $issue,
        public readonly ?string $environment,
        public readonly int $burst,
    ) {
    }
}
