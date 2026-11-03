<?php

declare(strict_types=1);

namespace App\Domains\Performance\Queries;

use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\LikePattern;
use App\Domains\Shared\Support\Sampling;
use App\Domains\Shared\Support\StatisticsCache;
use Illuminate\Support\Facades\DB;

class QueryStatisticsQuery
{
    public const array SORTS = ['total' => 'total_us', 'p95' => 'p95', 'count' => 'calls'];

    /**
     * The same query this many times in one trace is an N+1.
     */
    public const int N_PLUS_ONE_THRESHOLD = 5;

    /** Seconds of queries read for where each one comes from and how often it repeats per trace. */
    public const int SAMPLE_SECONDS = 86_400;

    /**
     * @return array{rows: list<array{sql_hash: string, sql: string, connection: string, calls: int, total_us: int, share: float, p95: int|null, max: int, most_from: string|null, location: string|null, per_trace: int, n_plus_one: bool}>, total_us: int, calls: int, distinct: int, sample_rate: float|null}
     */
    public function get(int $projectId, Period $period, ?string $environment = null, string $sort = 'total', ?string $search = null, ?string $connection = null, int $limit = 100): array
    {
        return StatisticsCache::remember('queries', $period, [$projectId, $environment, $sort, $search, $connection, $limit], fn (): array => $this->compute($projectId, $period, $environment, $sort, $search, $connection, $limit));
    }

    /**
     * @return array{rows: list<array{sql_hash: string, sql: string, connection: string, calls: int, total_us: int, share: float, p95: int|null, max: int, most_from: string|null, location: string|null, per_trace: int, n_plus_one: bool}>, total_us: int, calls: int, distinct: int, sample_rate: float|null}
     */
    private function compute(int $projectId, Period $period, ?string $environment, string $sort, ?string $search, ?string $connection, int $limit): array
    {
        $bindings = [$projectId, $period->since()->format('Y-m-d H:i:s')];
        $where = 'project_id = ? AND occurred_at >= ?';

        if ($environment !== null) {
            $where .= ' AND environment = ?';
            $bindings[] = $environment;
        }

        if ($connection !== null && $connection !== '') {
            $where .= ' AND connection = ?';
            $bindings[] = $connection;
        }

        // A query of a sampled run stands for 1 / sample_rate of them; see Sampling.
        $weight = Sampling::weight();
        $totals = DB::selectOne("SELECT COALESCE(SUM({$weight}), 0) AS calls, COALESCE(SUM({$weight} * duration_us), 0) AS total_us, COUNT(DISTINCT sql_hash) AS d, MIN(sample_rate) AS sample_rate FROM query_runs WHERE {$where}", $bindings);

        if ($search !== null && $search !== '') {
            $where .= ' AND sql ILIKE ?';
            $bindings[] = LikePattern::contains($search);
        }

        $order = self::SORTS[$sort] ?? 'total_us';

        $rows = DB::select("
            SELECT sql_hash, MAX(sql) AS sql, MAX(connection) AS connection, SUM({$weight}) AS calls,
                SUM({$weight} * duration_us) AS total_us, MAX(duration_us) AS max_us,
                percentile_disc(0.95) WITHIN GROUP (ORDER BY duration_us) FILTER (WHERE sampled) AS p95
            FROM query_runs WHERE {$where} GROUP BY sql_hash ORDER BY {$order} DESC NULLS LAST LIMIT ".(int) $limit, $bindings);

        $grand = max(1.0, (float) $totals->total_us);
        $hashes = array_values(array_map(fn (object $row): string => (string) ((array) $row)['sql_hash'], $rows));
        // ponytail: where a query comes from and its N+1 count read at most the last day,
        // whatever the period: joining a month of queries to their traces is the slowest
        // part of the page. A per-batch tally at ingest is the upgrade if a day is not enough.
        $sample = max($period->since(), now()->subSeconds(self::SAMPLE_SECONDS)->toImmutable())->format('Y-m-d H:i:s');
        $mostFrom = $this->mostFrom($projectId, $sample, $hashes);
        $locations = $this->locations($projectId, $sample, $hashes);
        $perTrace = $this->perTrace($projectId, $sample, $hashes);

        return [
            'rows' => array_values(array_map(function (object $row) use ($grand, $mostFrom, $locations, $perTrace): array {
                $r = (array) $row;
                $hash = (string) $r['sql_hash'];

                return [
                    'sql_hash' => (string) $r['sql_hash'],
                    'sql' => (string) $r['sql'],
                    'connection' => (string) $r['connection'],
                    'calls' => (int) round((float) $r['calls']),
                    'total_us' => (int) round((float) $r['total_us']),
                    'share' => round((float) $r['total_us'] / $grand, 4),
                    // Null when every call was kept only for being slow: no unskewed p95.
                    'p95' => $r['p95'] !== null ? (int) $r['p95'] : null,
                    'max' => (int) $r['max_us'],
                    'most_from' => $mostFrom[$hash] ?? null,
                    'location' => $locations[$hash] ?? null,
                    'per_trace' => $perTrace[$hash] ?? 1,
                    'n_plus_one' => ($perTrace[$hash] ?? 1) >= self::N_PLUS_ONE_THRESHOLD,
                ];
            }, $rows)),
            'total_us' => (int) round((float) $totals->total_us),
            'calls' => (int) round((float) $totals->calls),
            'distinct' => (int) $totals->d,
            'sample_rate' => $totals->sample_rate !== null && (float) $totals->sample_rate < 1 ? round((float) $totals->sample_rate, 4) : null,
        ];
    }

    /**
     * The route (or job) that ran each query most often, found through the shared trace id.
     *
     * @param  list<string>  $hashes
     * @return array<string, string>
     */
    private function mostFrom(int $projectId, string $since, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $fromRequests = DB::table('query_runs as q')
            ->join('request_runs as r', fn ($join) => $join->on('r.trace_id', '=', 'q.trace_id')->on('r.project_id', '=', 'q.project_id'))
            ->where('q.project_id', $projectId)->where('q.occurred_at', '>=', $since)->whereIn('q.sql_hash', $hashes)
            ->selectRaw("q.sql_hash, CONCAT(r.method, ' ', COALESCE(r.route, r.url)) AS source, COUNT(*) AS c")
            ->groupBy('q.sql_hash', 'source');

        $fromJobs = DB::table('query_runs as q')
            ->join('job_runs as j', fn ($join) => $join->on('j.trace_id', '=', 'q.trace_id')->on('j.project_id', '=', 'q.project_id'))
            ->where('q.project_id', $projectId)->where('q.occurred_at', '>=', $since)->whereIn('q.sql_hash', $hashes)
            ->selectRaw('q.sql_hash, j.name AS source, COUNT(*) AS c')
            ->groupBy('q.sql_hash', 'source');

        $best = [];

        foreach ($fromRequests->unionAll($fromJobs)->get() as $row) {
            $hash = (string) $row->sql_hash;

            if (! isset($best[$hash]) || (int) $row->c > $best[$hash]['c']) {
                $best[$hash] = ['source' => (string) $row->source, 'c' => (int) $row->c];
            }
        }

        return array_map(fn (array $b): string => $b['source'], $best);
    }

    /**
     * The line in the app's code that ran each query most often.
     *
     * @param  list<string>  $hashes
     * @return array<string, string>
     */
    private function locations(int $projectId, string $since, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $best = [];

        DB::table('query_runs')
            ->where('project_id', $projectId)->where('occurred_at', '>=', $since)
            ->whereIn('sql_hash', $hashes)->whereNotNull('location')
            ->selectRaw('sql_hash, location, COUNT(*) AS c')
            ->groupBy('sql_hash', 'location')
            ->get()
            ->each(function (object $row) use (&$best): void {
                $hash = (string) $row->sql_hash;

                if (! isset($best[$hash]) || (int) $row->c > $best[$hash]['c']) {
                    $best[$hash] = ['location' => (string) $row->location, 'c' => (int) $row->c];
                }
            });

        return array_map(fn (array $b): string => $b['location'], $best);
    }

    /**
     * The most times each query ran within one trace; many means an N+1.
     *
     * @param  list<string>  $hashes
     * @return array<string, int>
     */
    private function perTrace(int $projectId, string $since, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $perTrace = DB::table('query_runs')
            ->where('project_id', $projectId)->where('occurred_at', '>=', $since)
            ->whereIn('sql_hash', $hashes)->whereNotNull('trace_id')
            ->selectRaw('sql_hash, trace_id, COUNT(*) AS c')
            ->groupBy('sql_hash', 'trace_id');

        return DB::query()->fromSub($perTrace, 't')
            ->selectRaw('sql_hash, MAX(c) AS m')
            ->groupBy('sql_hash')
            ->pluck('m', 'sql_hash')
            ->map(fn ($m): int => (int) $m)
            ->all();
    }
}
