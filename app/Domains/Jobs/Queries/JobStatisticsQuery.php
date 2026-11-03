<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Queries;

use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\Percentiles;
use Illuminate\Support\Facades\DB;

class JobStatisticsQuery
{
    public const array SORTS = ['errors' => 'failure_rate', 'count' => 'runs', 'duration' => 'p95_duration', 'wait' => 'p95_wait'];

    /**
     * @return list<array<string, mixed>>
     */
    public function get(int $projectId, Period $period, ?string $environment = null, string $sort = 'errors', ?string $queue = null, int $limit = 100): array
    {
        $bindings = [$projectId, $period->since()->format('Y-m-d H:i:s')];
        $where = 'project_id = ? AND created_at >= ?';

        if ($environment !== null) {
            $where .= ' AND environment = ?';
            $bindings[] = $environment;
        }

        if ($queue !== null && $queue !== '') {
            $where .= ' AND queue = ?';
            $bindings[] = $queue;
        }

        $order = self::SORTS[$sort] ?? 'failure_rate';

        $rows = DB::select("
            WITH runs AS (
                SELECT name, queue, status, attempt, duration_us,
                    CASE WHEN started_at IS NOT NULL AND queued_at IS NOT NULL THEN EXTRACT(EPOCH FROM (started_at - queued_at)) * 1000000 END AS wait_us
                FROM job_runs WHERE {$where}
            )
            SELECT r.name, MAX(r.queue) AS queue, COUNT(*) AS runs,
                COUNT(*) FILTER (WHERE r.status = 'processed') AS processed,
                COUNT(*) FILTER (WHERE r.status = 'failed') AS failed,
                COUNT(*) FILTER (WHERE r.status = 'stuck') AS stuck,
                COUNT(*) FILTER (WHERE r.status = 'released') AS released,
                COUNT(*) FILTER (WHERE r.status IN ('queued', 'processing')) AS pending,
                COUNT(*) FILTER (WHERE r.attempt > 1) AS retries,
                COUNT(*) FILTER (WHERE r.status = 'failed')::float / COUNT(*) AS failure_rate,
                ".Percentiles::select('r.duration_us', '0.50', 'p50_duration').', '.Percentiles::select('r.duration_us', '0.95', 'p95_duration').', '.Percentiles::select('r.wait_us', '0.95', 'p95_wait')."
            FROM runs r
            GROUP BY r.name ORDER BY {$order} DESC LIMIT ".(int) $limit, $bindings);

        return array_values(array_map(function (object $row): array {
            $r = (array) $row;

            return [
                'name' => (string) $r['name'],
                'queue' => (string) $r['queue'],
                'runs' => (int) $r['runs'],
                'processed' => (int) $r['processed'],
                'failed' => (int) $r['failed'],
                'stuck' => (int) $r['stuck'],
                'released' => (int) $r['released'],
                'pending' => (int) $r['pending'],
                'retries' => (int) $r['retries'],
                'failure_rate' => (float) $r['failure_rate'],
                'p50_duration' => $r['p50_duration'] !== null ? (int) $r['p50_duration'] : null,
                'p95_duration' => $r['p95_duration'] !== null ? (int) $r['p95_duration'] : null,
                'p95_wait' => $r['p95_wait'] !== null ? (int) $r['p95_wait'] : null,
            ];
        }, $rows));
    }
}
