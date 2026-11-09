<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Jobs;

use App\Domains\DataSources\Actions\SyncDataSourceConnectionAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncDataSourceConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue((string) config('health.data_sources_queue'));
    }

    public function handle(SyncDataSourceConnectionAction $action): void
    {
        $action->execute($this->connectionId);
    }
}
