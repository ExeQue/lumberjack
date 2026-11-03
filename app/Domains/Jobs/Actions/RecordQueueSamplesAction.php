<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Actions;

use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Projects\Models\Project;

class RecordQueueSamplesAction
{
    /**
     * @param  list<array{connection: string, queue: string, size: int, oldest_age_s?: int|null}>  $queues
     */
    public function execute(Project $project, string $host, array $queues): void
    {
        if ($queues === []) {
            return;
        }

        $now = now();

        QueueSample::query()->insert(array_map(fn (array $q): array => [
            'project_id' => $project->id,
            'host' => mb_substr($host, 0, 255),
            'connection' => $q['connection'],
            'queue' => $q['queue'],
            'size' => $q['size'],
            'oldest_age_s' => $q['oldest_age_s'] ?? null,
            'sampled_at' => $now,
        ], $queues));
    }
}
