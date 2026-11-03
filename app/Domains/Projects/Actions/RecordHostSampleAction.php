<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\ProjectAgent;

class RecordHostSampleAction
{
    /**
     * @param  array<string, int|float|null>  $metrics  as the heartbeat sends them; nothing is stored when all are empty
     */
    public function execute(ProjectAgent $agent, array $metrics): ?HostSample
    {
        $metrics = array_intersect_key($metrics, array_flip(['cpu_load_1m', 'cpu_cores', 'memory_used_bytes', 'memory_total_bytes', 'disk_used_bytes', 'disk_total_bytes', 'php_memory_peak_bytes']));

        if (array_filter($metrics, fn ($value): bool => $value !== null) === []) {
            return null;
        }

        return HostSample::query()->create([...$metrics, 'project_agent_id' => $agent->id, 'sampled_at' => now()]);
    }
}
