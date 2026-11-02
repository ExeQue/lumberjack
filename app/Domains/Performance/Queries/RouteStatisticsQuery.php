<?php

declare(strict_types=1);

namespace App\Domains\Performance\Queries;

use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\Percentiles;
use Illuminate\Support\Facades\DB;

class RouteStatisticsQuery
{
    public const array SORTS = ['p95' => 'p95', 'count' => 'requests', 'errors' => 'error_rate', 'total' => 'total_us'];

    /**
     * @return list<array{method: string, route: string, requests: int, p50: int, p95: int, p99: int, error_rate: float, total_us: int}>
     */
    public function get(int $projectId, Period $period, ?string $environment = null, string $sort = 'p95', ?string $search = null, int $limit = 100): array
    {
        $bindings = [$projectId, $period->since()->format('Y-m-d H:i:s')];
        $where = 'project_id = ? AND occurred_at >= ?';

        if ($environment !== null) {
            $where .= ' AND environment = ?';
            $bindings[] = $environment;
        }

        if ($search !== null && $search !== '') {
            $where .= ' AND route LIKE ?';
            $bindings[] = '%'.$search.'%';
        }

        $order = self::SORTS[$sort] ?? 'p95';

        $p50 = Percentiles::select('duration_us', 0.50, 'p50');
        $p95 = Percentiles::select('duration_us', 0.95, 'p95');
        $p99 = Percentiles::select('duration_us', 0.99, 'p99');
        $limit = (int) $limit;

        $rows = DB::select("
            WITH ranked AS (
                SELECT method, COALESCE(route, url) AS route, duration_us, status_code,
                    ROW_NUMBER() OVER (PARTITION BY method, COALESCE(route, url) ORDER BY duration_us) AS rn,
                    COUNT(*) OVER (PARTITION BY method, COALESCE(route, url)) AS cnt
                FROM request_runs WHERE {$where}
            )
            SELECT method, route, MAX(cnt) AS requests, {$p50}, {$p95}, {$p99},
                COUNT(*) FILTER (WHERE status_code >= 500)::float / MAX(cnt) AS error_rate,
                SUM(duration_us) AS total_us
            FROM ranked GROUP BY method, route ORDER BY {$order} DESC LIMIT {$limit}", $bindings);

        return array_values(array_map(function (object $row): array {
            $r = (array) $row;

            return [
                'method' => (string) $r['method'],
                'route' => (string) $r['route'],
                'requests' => (int) $r['requests'],
                'p50' => (int) $r['p50'],
                'p95' => (int) $r['p95'],
                'p99' => (int) $r['p99'],
                'error_rate' => (float) $r['error_rate'],
                'total_us' => (int) $r['total_us'],
            ];
        }, $rows));
    }
}
