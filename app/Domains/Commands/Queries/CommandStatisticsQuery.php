<?php

declare(strict_types=1);

namespace App\Domains\Commands\Queries;

use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Per command in the period: runs, failures, average and longest duration, last run.
 */
class CommandStatisticsQuery
{
    /**
     * @return list<array{name: string, runs: int, failed: int, avg_us: int, max_us: int, last_run_at: string|null, last_status: string|null}>
     */
    public function get(int $projectId, Period $period, ?string $environment = null, int $limit = 100): array
    {
        $rows = DB::table('command_runs')
            ->where('project_id', $projectId)
            ->where('created_at', '>=', $period->since()->format('Y-m-d H:i:s'))
            ->when($environment !== null)->where('environment', $environment)
            ->selectRaw("name, COUNT(*) AS runs, COUNT(*) FILTER (WHERE status = 'failed') AS failed, COALESCE(AVG(duration_us), 0) AS avg_us, COALESCE(MAX(duration_us), 0) AS max_us, MAX(started_at) AS last_run_at")
            ->groupBy('name')
            ->orderByDesc('failed')
            ->orderByDesc('runs')
            ->limit($limit)
            ->get();

        $lastStatus = DB::table('command_runs')
            ->where('project_id', $projectId)
            ->whereIn('name', $rows->pluck('name'))
            ->whereIn('id', fn ($q) => $q->selectRaw('MAX(id)')->from('command_runs')->where('project_id', $projectId)->groupBy('name'))
            ->pluck('status', 'name');

        return array_values($rows->map(function (object $row) use ($lastStatus): array {
            $r = (array) $row;
            $name = (string) $r['name'];

            return [
                'name' => $name,
                'runs' => (int) $r['runs'],
                'failed' => (int) $r['failed'],
                'avg_us' => (int) round((float) $r['avg_us']),
                'max_us' => (int) $r['max_us'],
                'last_run_at' => $r['last_run_at'] !== null ? CarbonImmutable::parse((string) $r['last_run_at'])->toIso8601String() : null,
                'last_status' => isset($lastStatus[$name]) ? (string) $lastStatus[$name] : null,
            ];
        })->all());
    }
}
