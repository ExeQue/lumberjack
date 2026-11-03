<?php

declare(strict_types=1);

namespace App\Domains\Performance\Queries;

use App\Domains\Performance\Support\RequestTraffic;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\LikePattern;
use App\Domains\Shared\Support\Percentiles;
use App\Domains\Shared\Support\Sampling;
use App\Domains\Shared\Support\StatisticsCache;
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
     * @return list<array{method: string, route: string, requests: int, p50: int|null, p95: int|null, p99: int|null, error_rate: float, total_us: int, apdex: float|null, statuses: array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}}>
     */
    public function get(int $projectId, Period $period, ?string $environment = null, string $sort = 'p95', ?string $search = null, int $limit = 100): array
    {
        return StatisticsCache::remember('routes', $period, [$projectId, $environment, $sort, $search, $limit], fn (): array => $this->compute($projectId, $period, $environment, $sort, $search, $limit));
    }

    /**
     * Requests, errors, status codes and total time from every request (the SDK's counts,
     * or the runs weighed by their sample rate); percentiles and the Apdex split from the
     * runs the SDK picked, so runs kept only for being slow do not skew them. Apdex scales
     * the picked runs' share of satisfied and tolerated requests to the counted non-5xx ones.
     *
     * @return list<array{method: string, route: string, requests: int, p50: int|null, p95: int|null, p99: int|null, error_rate: float, total_us: int, apdex: float|null, statuses: array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}}>
     */
    private function compute(int $projectId, Period $period, ?string $environment, string $sort, ?string $search, int $limit): array
    {
        $since = $period->since();
        $pattern = $search !== null && $search !== '' ? LikePattern::contains($search) : null;
        $target = self::APDEX_TARGET_US;
        $tolerated = 4 * $target;

        $traffic = RequestTraffic::query($projectId, $since, null, $environment)
            ->when($pattern !== null)->where('route', 'ILIKE', $pattern)
            ->groupBy('method', 'route')
            ->selectRaw('method, route, SUM(requests) AS requests, SUM(duration_us) AS total_us,
                COALESCE(SUM(requests) FILTER (WHERE status_code BETWEEN 200 AND 299), 0) AS s2xx,
                COALESCE(SUM(requests) FILTER (WHERE status_code BETWEEN 300 AND 399), 0) AS s3xx,
                COALESCE(SUM(requests) FILTER (WHERE status_code BETWEEN 400 AND 499), 0) AS s4xx,
                COALESCE(SUM(requests) FILTER (WHERE status_code >= 500), 0) AS s5xx');

        $latency = DB::table('request_runs')
            ->where('project_id', $projectId)
            ->where('occurred_at', '>=', $since)
            ->where('sampled', true)
            ->when($environment !== null)->where('environment', $environment)
            ->when($pattern !== null)->whereRaw('COALESCE(route, url) ILIKE ?', [$pattern])
            ->groupByRaw('method, COALESCE(route, url)')
            ->selectRaw('method, COALESCE(route, url) AS route, '
                .Percentiles::select('duration_us', '0.50', 'p50').', '.Percentiles::select('duration_us', '0.95', 'p95').', '.Percentiles::select('duration_us', '0.99', 'p99').",
                COUNT(*) FILTER (WHERE status_code < 500) AS ok,
                COUNT(*) FILTER (WHERE status_code < 500 AND duration_us <= {$target}) AS satisfied,
                COUNT(*) FILTER (WHERE status_code < 500 AND duration_us > {$target} AND duration_us <= {$tolerated}) AS tolerating");

        // Null where requests other than 5xx came in but none of them was picked: unknown.
        $apdex = '(CASE WHEN t.requests <= t.s5xx THEN 0 WHEN COALESCE(l.ok, 0) = 0 THEN NULL
            ELSE (t.requests - t.s5xx) * (l.satisfied + l.tolerating / 2.0) / l.ok / t.requests END)';
        $order = match (self::SORTS[$sort] ?? 'p95') {
            'requests' => 't.requests',
            'error_rate' => 't.s5xx / t.requests',
            'total_us' => 't.total_us',
            'frustration' => "1 - {$apdex}",
            default => 'l.p95',
        };

        $rows = DB::query()->fromSub($traffic, 't')
            ->leftJoinSub($latency, 'l', fn ($join) => $join->on('l.method', '=', 't.method')->on('l.route', '=', 't.route'))
            ->where('t.requests', '>', 0)
            ->selectRaw("t.method, t.route, t.requests, t.total_us, t.s2xx, t.s3xx, t.s4xx, t.s5xx, l.p50, l.p95, l.p99, {$apdex} AS apdex")
            ->orderByRaw("{$order} DESC NULLS LAST")
            ->limit($limit)
            ->get();

        return array_values($rows->map(function (object $row): array {
            $r = (array) $row;
            $requests = (float) $r['requests'];

            return [
                'method' => (string) $r['method'],
                'route' => (string) $r['route'],
                'requests' => (int) round($requests),
                'p50' => $r['p50'] !== null ? (int) $r['p50'] : null,
                'p95' => $r['p95'] !== null ? (int) $r['p95'] : null,
                'p99' => $r['p99'] !== null ? (int) $r['p99'] : null,
                'error_rate' => (float) $r['s5xx'] / $requests,
                'total_us' => (int) round((float) $r['total_us']),
                'apdex' => $r['apdex'] !== null ? round((float) $r['apdex'], 2) : null,
                'statuses' => ['2xx' => (int) round((float) $r['s2xx']), '3xx' => (int) round((float) $r['s3xx']), '4xx' => (int) round((float) $r['s4xx']), '5xx' => (int) round((float) $r['s5xx'])],
            ];
        })->all());
    }

    /**
     * The lowest sample rate of the window's request runs, or null when every one was kept.
     */
    public function sampleRate(int $projectId, Period $period, ?string $environment = null): ?float
    {
        return StatisticsCache::remember('routes-sample-rate', $period, [$projectId, $environment], fn (): ?float => Sampling::lowestRate(
            DB::table('request_runs')->where('project_id', $projectId)->where('occurred_at', '>=', $period->since())->when($environment !== null)->where('environment', $environment),
        ));
    }
}
