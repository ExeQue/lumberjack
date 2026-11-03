<?php

declare(strict_types=1);

namespace App\Domains\Health\Jobs;

use App\Domains\Health\Actions\RunHealthCheckAction;
use App\Domains\Health\Models\HealthCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunHealthCheck implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A failed run is a result, not something to retry; the next interval tries again. */
    public int $tries = 1;

    public int $timeout = 90;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly HealthCheck $check,
    ) {
        $this->onQueue((string) config('health.queue'));
    }

    public function handle(RunHealthCheckAction $action): void
    {
        $action->execute($this->check);
    }
}
