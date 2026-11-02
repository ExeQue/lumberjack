<?php

declare(strict_types=1);

namespace App\Domains\Issues\Events;

use App\Domains\Issues\Models\IssueNote;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class IssueNoteAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly IssueNote $note,
    ) {
    }
}
