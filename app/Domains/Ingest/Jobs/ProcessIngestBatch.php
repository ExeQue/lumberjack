<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Jobs;

use App\Domains\Ingest\Actions\ProcessIngestBatchAction;
use App\Domains\Projects\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessIngestBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string QUEUE = 'ingest';

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $projectId,
        public readonly array $payload,
        public readonly bool $fromBrowser = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(ProcessIngestBatchAction $action): void
    {
        $project = Project::query()->find($this->projectId);

        if ($project === null) {
            return;
        }

        $action->execute($project, $this->payload, $this->fromBrowser);
    }
}
