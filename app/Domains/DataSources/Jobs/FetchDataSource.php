<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Jobs;

use App\Domains\DataSources\Actions\FetchDataSourceAction;
use App\Domains\DataSources\Models\DataSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchDataSource implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A failed fetch is kept as the source's error, not retried; the next interval tries again. */
    public int $tries = 1;

    public int $timeout = 90;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly DataSource $source,
    ) {
        // The health checks' low-priority queue.
        $this->onQueue((string) config('health.queue'));
    }

    public function handle(FetchDataSourceAction $action): void
    {
        $action->execute($this->source);
    }
}
