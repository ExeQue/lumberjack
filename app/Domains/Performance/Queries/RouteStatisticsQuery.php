<?php

declare(strict_types=1);

namespace App\Domains\Performance\Queries;

use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\LikePattern;
use App\Domains\Shared\Support\Percentiles;
use Illuminate\Support\Facades\DB;

class RouteStatisticsQuery
{
    public const array SORTS = ['p95' => 'p95', 'count' => 'requests', 'errors' => 'error_rate', 'total' => 'total_us', 'apdex' => 'frustration'];

    /**
     * Apdex target: a request within this is satisfied, within four times it is tolerated,
     * slower or a server error is frustrated.
     */
    // ponytail: one target for every route; a per-project setting when someone needs it.
    public const int APDEX_TARGET_US = 500_000;

    /**
     * @return list<array{method: string, route: string, requests: int, p50: int, p95: int, p99: int, error_rate: float, total_us: int, apdex: float, statuses: array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}}>
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
            $where .= ' AND route ILIKE ?';
            $bindings[] = LikePattern::contains($search);
        }

        $order = self::SORTS[$sort] ?? 'p95';

        $p50 = Percentiles::select('duration_us', 0.50, 'p50');
        $p95 = Percentiles::select('duration_us', 0.95, 'p95');
        $p99 = Percentiles::select('duration_us', 0.99, 'p99');
        $limit = (int) $limit;
        $target = self::APDEX_TARGET_US;
        $tolerated = 4 * $target;

        $rows = DB::select("
            WITH ranked AS (
                SELECT method, COALESCE(route, url) AS route, duration_us, status_code,
                    ROW_NUMBER() OVER (PARTITION BY method, COALESCE(route, url) ORDER BY duration_us) AS rn,
                    COUNT(*) OVER (PARTITION BY method, COALESCE(route, url)) AS cnt
                FROM request_runs WHERE {$where}
            )
            SELECT method, route, MAX(cnt) AS requests, {$p50}, {$p95}, {$p99},
                COUNT(*) FILTER (WHERE status_code >= 500)::float / MAX(cnt) AS error_rate,
                SUM(duration_us) AS total_us,
                COUNT(*) FILTER (WHERE status_code < 500 AND duration_us <= {$target}) AS satisfied,
                COUNT(*) FILTER (WHERE status_code < 500 AND duration_us > {$target} AND duration_us <= {$tolerated}) AS tolerating,
                1 - (COUNT(*) FILTER (WHERE status_code < 500 AND duration_us <= {$target}) + COUNT(*) FILTER (WHERE status_code < 500 AND duration_us > {$target} AND duration_us <= {$tolerated}) / 2.0) / MAX(cnt) AS frustration,
                COUNT(*) FILTER (WHERE status_code BETWEEN 200 AND 299) AS s2xx,
                COUNT(*) FILTER (WHERE status_code BETWEEN 300 AND 399) AS s3xx,
                COUNT(*) FILTER (WHERE status_code BETWEEN 400 AND 499) AS s4xx,
                COUNT(*) FILTER (WHERE status_code >= 500) AS s5xx
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
                'apdex' => round(((int) $r['satisfied'] + (int) $r['tolerating'] / 2) / max(1, (int) $r['requests']), 2),
                'statuses' => ['2xx' => (int) $r['s2xx'], '3xx' => (int) $r['s3xx'], '4xx' => (int) $r['s4xx'], '5xx' => (int) $r['s5xx']],
            ];
        }, $rows));
    }
}
