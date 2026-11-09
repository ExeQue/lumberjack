<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Jobs;

use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Models\DataSourceWindow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class FetchDataSourceWindow implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A failed pull is kept as the window's error, not retried; the next round tries again. */
    public int $tries = 1;

    public int $timeout = 60;

    /** Longer than a pull, shorter than the shortest step, so a worker that died holding it costs no round. */
    public int $uniqueFor = 240;

    public function __construct(
        public readonly int $windowId,
    ) {
        $this->onQueue((string) config('health.data_sources_queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->windowId;
    }

    public function handle(FetchDataSourceWindowAction $action): void
    {
        $action->execute($this->windowId);
    }

    /** A pull that died was claimed for its whole step: try again soon instead. */
    public function failed(): void
    {
        DataSourceWindow::query()->whereKey($this->windowId)->update(['next_fetch_at' => now()->addMinutes(FetchDataSourceWindowAction::EVERY_MINUTES)]);
    }
}
