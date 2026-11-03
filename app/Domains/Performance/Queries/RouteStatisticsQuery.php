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
     * @return list<array{method: string, route: string, route_name: string|null, domain: string|null, requests: int, p50: int|null, p95: int|null, p99: int|null, error_rate: float, total_us: int, apdex: float|null, statuses: array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}}>
     */
    public function get(int $projectId, Period $period, ?string $environment = null, string $sort = 'p95', ?string $search = null, int $limit = 100, ?string $domain = null): array
    {
        // Split by domain only when there is more than one to tell apart and none is picked.
        $split = $domain === null && count($this->domains($projectId, $period, $environment)) > 1;

        return StatisticsCache::remember('routes', $period, [$projectId, $environment, $sort, $search, $limit, $domain, $split], fn (): array => $this->compute($projectId, $period, $environment, $sort, $search, $limit, $domain, $split));
    }

    /**
     * The domains requests came in on in the window, without the empty one of counts an
     * older SDK sent.
     *
     * @return list<string>
     */
    public function domains(int $projectId, Period $period, ?string $environment = null): array
    {
        return StatisticsCache::remember('routes-domains', $period, [$projectId, $environment], fn (): array => array_values(
            RequestTraffic::query($projectId, $period->since(), null, $environment)
                ->where('domain', '<>', '')
                ->distinct()
                ->orderBy('domain')
                ->pluck('domain')
                ->map(fn (mixed $domain): string => (string) $domain)
                ->all(),
        ));
    }

    /**
     * Requests, errors, status codes and total time from every request (the SDK's counts,
     * or the runs weighed by their sample rate); percentiles and the Apdex split from the
     * runs the SDK picked, so runs kept only for being slow do not skew them. Apdex scales
     * the picked runs' share of satisfied and tolerated requests to the counted non-5xx ones.
     * A search matches the route or its name; split, each domain of a route is a row.
     *
     * @return list<array{method: string, route: string, route_name: string|null, domain: string|null, requests: int, p50: int|null, p95: int|null, p99: int|null, error_rate: float, total_us: int, apdex: float|null, statuses: array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}}>
     */
    private function compute(int $projectId, Period $period, ?string $environment, string $sort, ?string $search, int $limit, ?string $domain, bool $split): array
    {
        $since = $period->since();
        $pattern = $search !== null && $search !== '' ? LikePattern::contains($search) : null;
        $target = self::APDEX_TARGET_US;
        $tolerated = 4 * $target;
        $runs = fn () => DB::table('request_runs')
            ->where('project_id', $projectId)
            ->where('occurred_at', '>=', $since)
            ->when($environment !== null)->where('environment', $environment)
            ->when($domain !== null && $domain !== '')->where('domain', $domain)
            ->when($domain === '')->whereNull('domain');
        // The counts carry no route name: a name finds its routes through the runs.
        $named = fn () => $runs()->where('route_name', 'ILIKE', $pattern)->whereNotNull('route')->distinct()->select('route');
        $by = $split ? ', domain' : '';

        $traffic = RequestTraffic::query($projectId, $since, null, $environment, $domain)
            ->when($pattern !== null)->where(fn ($q) => $q->where('route', 'ILIKE', $pattern)->orWhereIn('route', $named()))
            ->groupByRaw("method, route{$by}")
            ->selectRaw("method, route{$by}, SUM(requests) AS requests, SUM(duration_us) AS total_us,
                COALESCE(SUM(requests) FILTER (WHERE status_code BETWEEN 200 AND 299), 0) AS s2xx,
                COALESCE(SUM(requests) FILTER (WHERE status_code BETWEEN 300 AND 399), 0) AS s3xx,
                COALESCE(SUM(requests) FILTER (WHERE status_code BETWEEN 400 AND 499), 0) AS s4xx,
                COALESCE(SUM(requests) FILTER (WHERE status_code >= 500), 0) AS s5xx");

        $runDomain = $split ? ", COALESCE(domain, '')" : '';
        // ponytail: the name of the picked runs; a route only kept for being slow shows none.
        $latency = $runs()
            ->where('sampled', true)
            ->when($pattern !== null)->where(fn ($q) => $q->whereRaw('COALESCE(route, url) ILIKE ?', [$pattern])->orWhere('route_name', 'ILIKE', $pattern))
            ->groupByRaw("method, COALESCE(route, url){$runDomain}")
            ->selectRaw("method, COALESCE(route, url) AS route{$runDomain}".($split ? ' AS domain' : '').', MAX(route_name) AS route_name, '
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
            ->leftJoinSub($latency, 'l', fn ($join) => $join->on('l.method', '=', 't.method')->on('l.route', '=', 't.route')->when($split)->on('l.domain', '=', 't.domain'))
            ->where('t.requests', '>', 0)
            ->selectRaw('t.method, t.route, '.($split ? 't.domain' : 'NULL AS domain').", l.route_name, t.requests, t.total_us, t.s2xx, t.s3xx, t.s4xx, t.s5xx, l.p50, l.p95, l.p99, {$apdex} AS apdex")
            ->orderByRaw("{$order} DESC NULLS LAST")
            ->limit($limit)
            ->get();

        return array_values($rows->map(function (object $row): array {
            $r = (array) $row;
            $requests = (float) $r['requests'];

            return [
                'method' => (string) $r['method'],
                'route' => (string) $r['route'],
                'route_name' => $r['route_name'] !== null ? (string) $r['route_name'] : null,
                'domain' => $r['domain'] !== null ? (string) $r['domain'] : null,
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
    public function sampleRate(int $projectId, Period $period, ?string $environment = null, ?string $domain = null): ?float
    {
        return StatisticsCache::remember('routes-sample-rate', $period, [$projectId, $environment, $domain], fn (): ?float => Sampling::lowestRate(
            DB::table('request_runs')->where('project_id', $projectId)->where('occurred_at', '>=', $period->since())->when($environment !== null)->where('environment', $environment)
                ->when($domain !== null)->where('domain', $domain),
        ));
    }
}
