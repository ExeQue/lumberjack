<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Queries;

use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\Percentiles;
use App\Domains\Shared\Support\StatisticsCache;
use App\Domains\Shared\Support\TimeSeries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Each queue as agents report it: how many jobs wait now, how long the oldest has
 * waited, and its size over the period, with the p95 wait of the jobs that ran.
 */
class QueueStatisticsQuery
{
    public const int LIMIT = 20;

    /**
     * @return list<array{connection: string, queue: string, size: int|null, oldest_age_s: int|null, workers: int|null, sampled_at: string|null, p95_wait: int|null, series: list<array{t: string, v: int|float}>}>
     */
    public function get(int $projectId, Period $period, ?string $environment = null): array
    {
        return StatisticsCache::remember('queues', $period, [$projectId, $environment], fn (): array => $this->compute($projectId, $period, $environment));
    }

    /**
     * @return list<array{connection: string, queue: string, size: int|null, oldest_age_s: int|null, workers: int|null, sampled_at: string|null, p95_wait: int|null, series: list<array{t: string, v: int|float}>}>
     */
    private function compute(int $projectId, Period $period, ?string $environment): array
    {
        $since = $period->since()->format('Y-m-d H:i:s');
        $key = fn (array $r): string => $r['connection'].':'.$r['queue'];

        // Queue size is one number per queue, whichever host reports it: the latest sample wins.
        $latest = collect(DB::select('
            SELECT DISTINCT ON (connection, queue) connection, queue, size, oldest_age_s, workers, sampled_at
            FROM queue_samples WHERE project_id = ? AND sampled_at >= ?
            ORDER BY connection, queue, sampled_at DESC
            LIMIT '.self::LIMIT, [$projectId, $since]))->map(fn (object $r): array => (array) $r)->keyBy($key);

        $bindings = [$projectId, $since];
        $where = 'project_id = ? AND created_at >= ? AND started_at IS NOT NULL AND queued_at IS NOT NULL';

        if ($environment !== null) {
            $where .= ' AND environment = ?';
            $bindings[] = $environment;
        }

        $waits = collect(DB::select("
            SELECT connection, queue, ".Percentiles::select('EXTRACT(EPOCH FROM (started_at - queued_at)) * 1000000', '0.95', 'p95_wait')."
            FROM job_runs WHERE {$where} GROUP BY connection, queue ORDER BY connection, queue LIMIT ".self::LIMIT, $bindings))
            ->map(fn (object $r): array => (array) $r)->keyBy($key);

        // The size over the period of every sampled queue, in one query.
        $sampled = array_values($latest->keys()->all());
        $series = $sampled === [] ? [] : TimeSeries::countBy(DB::table('queue_samples')->where('project_id', $projectId), $period, "connection || ':' || queue", $sampled, 'sampled_at', 'MAX(size)');

        return array_values($latest->keys()->merge($waits->keys())->unique()->sort()->take(self::LIMIT)->map(function (string $key) use ($latest, $waits, $series): array {
            $sample = $latest->get($key);
            $wait = $waits->get($key);
            [$connection, $queue] = explode(':', $key, 2);

            return [
                'connection' => $connection,
                'queue' => $queue,
                'size' => $sample !== null ? (int) $sample['size'] : null,
                'oldest_age_s' => isset($sample['oldest_age_s']) ? (int) $sample['oldest_age_s'] : null,
                'workers' => isset($sample['workers']) ? (int) $sample['workers'] : null,
                'sampled_at' => $sample !== null ? CarbonImmutable::parse($sample['sampled_at'])->toIso8601String() : null,
                'p95_wait' => isset($wait['p95_wait']) ? (int) $wait['p95_wait'] : null,
                'series' => $series[$key] ?? [],
            ];
        })->all());
    }
}
