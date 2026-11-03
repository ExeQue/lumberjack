<?php

declare(strict_types=1);

namespace App\Domains\Live\Jobs;

use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * The broadcast after a throttled one, so the last change of a burst reaches the page.
 */
class BroadcastTrailingProjectChange implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public readonly int $projectId,
        public readonly ChangeKind $kind,
    ) {
    }

    public function handle(BroadcastProjectChangeAction $action): void
    {
        $action->trailing($this->projectId, $this->kind);
    }
}
