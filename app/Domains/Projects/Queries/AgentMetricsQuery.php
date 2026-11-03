<?php

declare(strict_types=1);

namespace App\Domains\Projects\Queries;

use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\TimeSeries;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each agent's host reported last, with its memory use and load per hour over the
 * last day, for the agents table.
 */
class AgentMetricsQuery
{
    /**
     * @param  Collection<int, ProjectAgent>  $agents
     * @return array<int, array{project_agent_id: int, sampled_at: string, cpu_load_1m: float|null, cpu_cores: float|null, memory_used_bytes: int|null, memory_total_bytes: int|null, disk_used_bytes: int|null, disk_total_bytes: int|null, php_memory_peak_bytes: int|null, memory_series: list<float>, load_series: list<float>}>
     */
    public function get(Collection $agents): array
    {
        return array_map(fn (array $metrics): array => [
            ...$metrics,
            'memory_series' => $this->series($metrics['project_agent_id'], 'MAX(memory_used_bytes::float / NULLIF(memory_total_bytes, 0))'),
            'load_series' => $this->series($metrics['project_agent_id'], 'MAX(cpu_load_1m)'),
        ], $this->latest($agents));
    }

    /**
     * What each agent's host reported last in the last day, in one query: the metrics API
     * and the status screen show this without the series.
     *
     * @param  Collection<int, ProjectAgent>|list<int>  $agents
     * @return array<int, array{project_agent_id: int, sampled_at: string, cpu_load_1m: float|null, cpu_cores: float|null, memory_used_bytes: int|null, memory_total_bytes: int|null, disk_used_bytes: int|null, disk_total_bytes: int|null, php_memory_peak_bytes: int|null}>
     */
    public function latest(Collection|array $agents): array
    {
        $ids = $agents instanceof Collection ? $agents->modelKeys() : $agents;

        if ($ids === []) {
            return [];
        }

        $latest = HostSample::query()
            ->select(DB::raw('DISTINCT ON (project_agent_id) *'))
            ->whereIn('project_agent_id', $ids)
            ->where('sampled_at', '>=', now()->subDay())
            ->orderBy('project_agent_id')->orderByDesc('sampled_at')
            ->get();

        $metrics = [];

        foreach ($latest as $sample) {
            $metrics[$sample->project_agent_id] = [
                'project_agent_id' => $sample->project_agent_id,
                'sampled_at' => $sample->sampled_at->toIso8601String(),
                'cpu_load_1m' => $sample->cpu_load_1m,
                'cpu_cores' => $sample->cpu_cores,
                'memory_used_bytes' => $sample->memory_used_bytes,
                'memory_total_bytes' => $sample->memory_total_bytes,
                'disk_used_bytes' => $sample->disk_used_bytes,
                'disk_total_bytes' => $sample->disk_total_bytes,
                'php_memory_peak_bytes' => $sample->php_memory_peak_bytes,
            ];
        }

        return $metrics;
    }

    /**
     * @param  literal-string  $aggregate
     * @return list<float>
     */
    private function series(int $agentId, string $aggregate): array
    {
        return array_map(
            fn (array $point): float => round((float) $point['v'], 3),
            TimeSeries::count(DB::table('host_samples')->where('project_agent_id', $agentId), Period::Day, 'sampled_at', $aggregate),
        );
    }
}
