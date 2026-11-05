<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Queries;

use App\Domains\Metrics\Data\MetricFilter;
use App\Domains\Metrics\Data\MetricWindow;
use App\Domains\Metrics\Enums\Metric;
use App\Domains\Metrics\Enums\MetricGroup;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Performance\Support\RequestTraffic;
use App\Domains\Shared\Support\Sampling;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One metric over any window of any set of projects: as a series of buckets, or as one
 * value for the whole window. The metrics API, its Prometheus page and the status
 * screen's widgets all read through here.
 *
 * Request totals come from the SDK's request counts (RequestTraffic), percentiles and the
 * Apdex split only from the runs the SDK picked. Filtering or splitting requests by
 * release reads the runs weighed by their sample rate, as the counts carry no release.
 * Queue size and host use are gauges: their value is the latest sample in the window,
 * summed over queues and the highest over hosts. The database metrics read the results
 * of reported health checks: a check's largest figure per bucket (several hosts report
 * the same database), summed over checks, and the highest share of connections in use.
 */
class MetricSeriesQuery
{
    /** How many series a split returns, the busiest first. */
    public const int TOP = 10;

    /** Series name for rows without a value of the split, e.g. no environment. */
    public const string NONE = '(none)';

    /**
     * @return list<array{t: string, series: string, value: float|null}>
     */
    public function series(Metric $metric, MetricFilter $filter, MetricWindow $window, int $top = self::TOP): array
    {
        $group = $filter->groupBy !== null && in_array($filter->groupBy, $metric->groups(), true) ? $filter->groupBy : null;

        $rows = match ($metric) {
            Metric::Apdex => $this->apdex($filter, $window, $group),
            Metric::QueueSize, Metric::QueueWorkers => $this->queueSeries($metric, $filter, $window, $group),
            Metric::DbSize, Metric::DbConnections, Metric::DbConnectionsUsed => $this->databaseSeries($metric, $filter, $window, $group),
            default => $this->bucketed($metric, $filter, $window, $group),
        };

        return $this->points($metric, $rows, $window, $group !== null, $top);
    }

    public function value(Metric $metric, MetricFilter $filter, MetricWindow $window): ?float
    {
        $value = match ($metric) {
            Metric::Apdex => $this->apdex($filter, $window, null, bucketed: false)[0]['value'] ?? null,
            Metric::QueueSize, Metric::QueueWorkers => $this->queueNow($metric, $filter, $window),
            Metric::CpuLoad, Metric::MemoryUsed, Metric::DiskUsed => $this->hostNow($metric, $filter, $window),
            Metric::DbSize, Metric::DbConnections, Metric::DbConnectionsUsed => $this->databaseNow($metric, $filter, $window),
            default => (function () use ($metric, $filter, $window): mixed {
                [$query, , $aggregate] = $this->source($metric, $filter, $window, null);

                return $query->selectRaw("{$aggregate} AS value")->value('value');
            })(),
        };

        return $value !== null ? $this->round($metric, (float) $value) : ($metric->isCount() ? 0.0 : null);
    }

    /**
     * The share of the window's sampled requests that took at most $ms, null without any:
     * a latency objective's good share, read from the runs the SDK picked like the percentiles.
     */
    public function shareWithin(MetricFilter $filter, MetricWindow $window, int $ms): ?float
    {
        $value = $this->sampledRuns($filter, $window)
            ->selectRaw('COUNT(*) FILTER (WHERE duration_us <= ?)::float / NULLIF(COUNT(*), 0) AS value', [$ms * 1000])
            ->value('value');

        return $value !== null ? (float) $value : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bucketed(Metric $metric, MetricFilter $filter, MetricWindow $window, ?MetricGroup $group): array
    {
        [$query, $time, $aggregate, $groupColumn] = $this->source($metric, $filter, $window, $group);

        return $this->rows($query, $time, $aggregate, $groupColumn, $window);
    }

    /**
     * The rows a metric reads, already filtered to the window, with the time column to
     * bucket by, the aggregate and the column of the split.
     *
     * @return array{Builder, literal-string, literal-string, literal-string|null}
     */
    private function source(Metric $metric, MetricFilter $filter, MetricWindow $window, ?MetricGroup $group): array
    {
        return match ($metric) {
            Metric::Requests, Metric::Errors, Metric::ErrorRate => [
                $this->traffic($filter, $window, $group),
                'occurred_at',
                match ($metric) {
                    Metric::Requests => 'SUM(requests)',
                    Metric::Errors => 'COALESCE(SUM(requests) FILTER (WHERE status_code >= 500), 0)',
                    default => 'COALESCE(SUM(requests) FILTER (WHERE status_code >= 500), 0) / NULLIF(SUM(requests), 0)',
                },
                $this->column($group),
            ],
            Metric::P50, Metric::P95, Metric::P99 => [
                $this->sampledRuns($filter, $window),
                'occurred_at',
                'percentile_disc('.['p50' => '0.50', 'p95' => '0.95', 'p99' => '0.99'][$metric->value].') WITHIN GROUP (ORDER BY duration_us) / 1000.0',
                $this->runGroup($group),
            ],
            Metric::Occurrences, Metric::Users => [
                $this->match(DB::table('occurrences')->whereIn('project_id', $filter->projectIds)
                    ->where('occurred_at', '>=', $window->from)->where('occurred_at', '<', $window->to)
                    ->when($filter->environment !== null)->where('environment', $filter->environment)
                    ->when($filter->release !== null)->where('release', $filter->release), 'host', $filter->host),
                'occurred_at',
                $metric === Metric::Users ? 'COUNT(DISTINCT user_id)' : 'COUNT(*)',
                $this->column($group, nullableEnvironment: true),
            ],
            Metric::NewIssues => [
                DB::table('issues')->whereIn('project_id', $filter->projectIds)
                    ->where('first_seen_at', '>=', $window->from)->where('first_seen_at', '<', $window->to)
                    ->when($filter->environment !== null)->where('first_environment', $filter->environment)
                    ->when($filter->release !== null)->where('first_release', $filter->release),
                'first_seen_at',
                'COUNT(*)',
                match ($group) {
                    MetricGroup::Environment => "COALESCE(first_environment, '')",
                    MetricGroup::Release => 'first_release',
                    default => null,
                },
            ],
            Metric::JobRuns, Metric::JobFailures, Metric::QueueWait => [
                $this->match($this->match($this->match(DB::table('job_runs')->whereIn('project_id', $filter->projectIds)
                    ->where('created_at', '>=', $window->from)->where('created_at', '<', $window->to)
                    ->when($filter->environment !== null)->where('environment', $filter->environment)
                    ->when($metric === Metric::QueueWait)->whereNotNull('started_at')
                    ->when($metric === Metric::QueueWait)->whereNotNull('queued_at'), 'queue', $filter->queue), 'name', $filter->job), 'host', $filter->host),
                'created_at',
                match ($metric) {
                    Metric::JobRuns => 'COUNT(*)',
                    Metric::JobFailures => "COUNT(*) FILTER (WHERE status = 'failed')",
                    default => 'percentile_disc(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (started_at - queued_at)) * 1000)',
                },
                $this->column($group, nullableEnvironment: true),
            ],
            Metric::CpuLoad, Metric::MemoryUsed, Metric::DiskUsed => [
                $this->hostSamples($filter, $window),
                's.sampled_at',
                'MAX('.$this->hostShare($metric).')',
                $group === MetricGroup::Host ? 'a.host' : null,
            ],
            Metric::LcpP75, Metric::InpP75, Metric::ClsP75 => [
                $this->match(DB::table('web_vitals')->whereIn('project_id', $filter->projectIds)
                    ->where('occurred_at', '>=', $window->from)->where('occurred_at', '<', $window->to)
                    ->when($filter->environment !== null)->where('environment', $filter->environment)
                    ->when($filter->release !== null)->where('release', $filter->release), 'route', $filter->route),
                'occurred_at',
                match ($metric) {
                    Metric::LcpP75 => 'percentile_disc(0.75) WITHIN GROUP (ORDER BY lcp_us) / 1000.0',
                    Metric::InpP75 => 'percentile_disc(0.75) WITHIN GROUP (ORDER BY inp_us) / 1000.0',
                    default => 'percentile_disc(0.75) WITHIN GROUP (ORDER BY cls)',
                },
                $this->column($group, nullableEnvironment: true),
            ],
            Metric::Apdex, Metric::QueueSize, Metric::QueueWorkers, Metric::DbSize, Metric::DbConnections, Metric::DbConnectionsUsed => throw new LogicException("{$metric->value} has its own query."),
        };
    }

    /**
     * Every request of the window as (environment, route, status_code, occurred_at,
     * requests): the SDK's counts, or the weighed runs when a release is asked for.
     */
    private function traffic(MetricFilter $filter, MetricWindow $window, ?MetricGroup $group): Builder
    {
        if ($filter->release !== null || $group === MetricGroup::Release) {
            $weight = Sampling::weight();
            $runs = DB::table('request_runs')->whereIn('project_id', $filter->projectIds)
                ->where('occurred_at', '>=', $window->from)->where('occurred_at', '<', $window->to)
                ->when($filter->environment !== null)->where('environment', $filter->environment)
                ->when($filter->release !== null)->where('release', $filter->release)
                ->selectRaw("COALESCE(environment, '') AS environment, COALESCE(domain, '') AS domain, COALESCE(route, url) AS route, release, status_code, occurred_at, {$weight} AS requests");
            $traffic = DB::query()->fromSub($runs, 'traffic');
        } else {
            $traffic = RequestTraffic::query($filter->projectIds, $window->from, $window->to, $filter->environment);
        }

        return $this->match($this->matchRoute($traffic, 'route', $filter, $window), 'domain', $filter->domain);
    }

    /**
     * The runs the SDK picked: a run kept only for being slow would skew percentiles.
     */
    private function sampledRuns(MetricFilter $filter, MetricWindow $window): Builder
    {
        $runs = DB::table('request_runs')->whereIn('project_id', $filter->projectIds)
            ->where('occurred_at', '>=', $window->from)->where('occurred_at', '<', $window->to)
            ->where('sampled', true)
            ->when($filter->environment !== null)->where('environment', $filter->environment)
            ->when($filter->release !== null)->where('release', $filter->release);

        return $this->match($this->match($this->matchRoute($runs, 'COALESCE(route, url)', $filter, $window, named: true), 'host', $filter->host), "COALESCE(domain, '')", $filter->domain);
    }

    /**
     * @return literal-string|null
     */
    private function runGroup(?MetricGroup $group): ?string
    {
        return match ($group) {
            MetricGroup::Environment => "COALESCE(environment, '')",
            MetricGroup::Route => 'COALESCE(route, url)',
            MetricGroup::Domain => "COALESCE(domain, '')",
            MetricGroup::Host => 'host',
            MetricGroup::Release => 'release',
            default => null,
        };
    }

    /**
     * Apdex per bucket (or for the whole window): the picked runs' share of satisfied and
     * tolerated requests, scaled to the counted requests other than 5xx, as on the
     * routes page.
     *
     * @return list<array<string, mixed>>
     */
    private function apdex(MetricFilter $filter, MetricWindow $window, ?MetricGroup $group, bool $bucketed = true): array
    {
        $target = RouteStatisticsQuery::APDEX_TARGET_US;
        $tolerated = 4 * $target;

        $traffic = $this->rows(
            $this->traffic($filter, $window, $group),
            'occurred_at',
            'SUM(requests) AS requests, COALESCE(SUM(requests) FILTER (WHERE status_code >= 500), 0)',
            $this->column($group),
            $window,
            $bucketed,
            'errors',
        );
        $runs = $this->rows(
            $this->sampledRuns($filter, $window),
            'occurred_at',
            "COUNT(*) FILTER (WHERE status_code < 500) AS ok, COUNT(*) FILTER (WHERE status_code < 500 AND duration_us <= {$target}) AS satisfied,
                COUNT(*) FILTER (WHERE status_code < 500 AND duration_us > {$target} AND duration_us <= {$tolerated})",
            $this->runGroup($group),
            $window,
            $bucketed,
            'tolerating',
        );

        $key = fn (array $row): string => $row['bucket'].'|'.$row['series'];
        $runs = array_combine(array_map($key, $runs), $runs);

        return array_map(function (array $row) use ($runs, $key): array {
            $requests = (float) $row['requests'];
            $run = $runs[$key($row)] ?? null;
            $ok = (float) ($run['ok'] ?? 0);

            $value = match (true) {
                $requests <= 0 => null,
                $requests <= (float) $row['errors'] => 0.0,
                $ok <= 0 => null,
                default => ($requests - (float) $row['errors']) * ((float) $run['satisfied'] + (float) $run['tolerating'] / 2) / $ok / $requests,
            };

            return ['bucket' => $row['bucket'], 'series' => $row['series'], 'value' => $value];
        }, $traffic);
    }

    /**
     * The largest size each queue reported per bucket, added up over the queues.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Jobs waiting or workers running: each queue's largest per bucket, added up over the
     * queues (a worker taking two queues counts on both).
     *
     * @return list<array<string, mixed>>
     */
    private function queueSeries(Metric $metric, MetricFilter $filter, MetricWindow $window, ?MetricGroup $group): array
    {
        $size = $window->bucketSeconds;
        $by = $this->column($group) ?? 'NULL';
        $column = $this->queueColumn($metric);
        $perQueue = $this->queueSamples($filter, $window)
            ->when($column === 'workers')->whereNotNull('workers')
            ->selectRaw("FLOOR(EXTRACT(EPOCH FROM sampled_at) / ?) * ? AS bucket, {$by} AS series, connection, queue, MAX({$column}) AS value", [$size, $size])
            ->groupByRaw('1, 2, connection, queue');

        return array_values(DB::query()->fromSub($perQueue, 'q')
            ->selectRaw('bucket, series, SUM(value) AS value')
            ->groupBy('bucket', 'series')
            ->get()
            ->map(fn (object $row): array => ['bucket' => (int) $row->bucket, 'series' => $row->series !== null ? (string) $row->series : null, 'value' => (float) $row->value])
            ->all());
    }

    private function queueNow(Metric $metric, MetricFilter $filter, MetricWindow $window): mixed
    {
        $column = $this->queueColumn($metric);
        $latest = $this->queueSamples($filter, $window)
            ->when($column === 'workers')->whereNotNull('workers')
            ->selectRaw("DISTINCT ON (project_id, connection, queue) {$column} AS value")
            ->orderBy('project_id')->orderBy('connection')->orderBy('queue')->orderByDesc('sampled_at');

        return DB::query()->fromSub($latest, 'q')->selectRaw('SUM(value) AS value')->value('value');
    }

    private function queueColumn(Metric $metric): string
    {
        return $metric === Metric::QueueWorkers ? 'workers' : 'size';
    }

    private function queueSamples(MetricFilter $filter, MetricWindow $window): Builder
    {
        return $this->match($this->match(DB::table('queue_samples')->whereIn('project_id', $filter->projectIds)
            ->where('sampled_at', '>=', $window->from)->where('sampled_at', '<', $window->to), 'queue', $filter->queue), 'host', $filter->host);
    }

    /**
     * Each check's largest figure per bucket, added up over the checks (the highest for
     * the share of connections in use).
     *
     * @return list<array<string, mixed>>
     */
    private function databaseSeries(Metric $metric, MetricFilter $filter, MetricWindow $window, ?MetricGroup $group): array
    {
        $size = $window->bucketSeconds;
        $by = match ($group) {
            MetricGroup::Environment => 'c.environment',
            MetricGroup::Check => 'c.slug',
            default => 'NULL',
        };
        $perCheck = $this->databaseResults($metric, $filter, $window)
            ->selectRaw("FLOOR(EXTRACT(EPOCH FROM r.checked_at) / ?) * ? AS bucket, {$by} AS series, r.health_check_id, MAX({$this->databaseValue($metric)}) AS value", [$size, $size])
            ->groupByRaw('1, 2, r.health_check_id');

        return array_values(DB::query()->fromSub($perCheck, 'd')
            ->selectRaw('bucket, series, '.$this->databaseCombine($metric).'(value) AS value')
            ->groupBy('bucket', 'series')
            ->get()
            ->map(fn (object $row): array => ['bucket' => (int) $row->bucket, 'series' => $row->series !== null ? (string) $row->series : null, 'value' => $row->value !== null ? (float) $row->value : null])
            ->all());
    }

    private function databaseNow(Metric $metric, MetricFilter $filter, MetricWindow $window): mixed
    {
        $latest = $this->databaseResults($metric, $filter, $window)
            ->selectRaw("DISTINCT ON (r.health_check_id) {$this->databaseValue($metric)} AS value")
            ->orderBy('r.health_check_id')->orderByDesc('r.checked_at');

        return DB::query()->fromSub($latest, 'd')->selectRaw($this->databaseCombine($metric).'(value) AS value')->value('value');
    }

    /**
     * Results of the window that carry the metric's figures.
     */
    private function databaseResults(Metric $metric, MetricFilter $filter, MetricWindow $window): Builder
    {
        $key = $metric === Metric::DbSize ? 'size_bytes' : 'connections';

        return $this->match(DB::table('health_check_results as r')
            ->join('health_checks as c', 'c.id', '=', 'r.health_check_id')
            ->whereIn('r.project_id', $filter->projectIds)
            ->where('r.checked_at', '>=', $window->from)->where('r.checked_at', '<', $window->to)
            ->whereRaw("(r.metrics->>'{$key}') IS NOT NULL")
            ->when($metric === Metric::DbConnectionsUsed)->whereRaw("(r.metrics->>'max_connections') IS NOT NULL")
            ->when($filter->environment !== null)->where('c.environment', $filter->environment), 'c.slug', $filter->check);
    }

    /**
     * @return literal-string
     */
    private function databaseValue(Metric $metric): string
    {
        return match ($metric) {
            Metric::DbSize => "(r.metrics->>'size_bytes')::float",
            Metric::DbConnections => "(r.metrics->>'connections')::float",
            default => "(r.metrics->>'connections')::float / NULLIF((r.metrics->>'max_connections')::float, 0)",
        };
    }

    /**
     * @return literal-string
     */
    private function databaseCombine(Metric $metric): string
    {
        return $metric === Metric::DbConnectionsUsed ? 'MAX' : 'SUM';
    }

    private function hostNow(Metric $metric, MetricFilter $filter, MetricWindow $window): mixed
    {
        $latest = $this->hostSamples($filter, $window)
            ->selectRaw('DISTINCT ON (s.project_agent_id) s.*')
            ->orderBy('s.project_agent_id')->orderByDesc('s.sampled_at');

        return DB::query()->fromSub($latest, 's')->selectRaw('MAX('.$this->hostShare($metric).') AS value')->value('value');
    }

    private function hostSamples(MetricFilter $filter, MetricWindow $window): Builder
    {
        return $this->match(DB::table('host_samples as s')
            ->join('project_agents as a', 'a.id', '=', 's.project_agent_id')
            ->whereIn('a.project_id', $filter->projectIds)
            ->where('s.sampled_at', '>=', $window->from)->where('s.sampled_at', '<', $window->to), 'a.host', $filter->host);
    }

    /**
     * CPU as the 1 minute load per core (1 is every core busy), memory and disk as the
     * share in use.
     *
     * @return literal-string
     */
    private function hostShare(Metric $metric): string
    {
        return match ($metric) {
            Metric::CpuLoad => 's.cpu_load_1m / NULLIF(s.cpu_cores, 0)',
            Metric::MemoryUsed => 's.memory_used_bytes::float / NULLIF(s.memory_total_bytes, 0)',
            default => 's.disk_used_bytes::float / NULLIF(s.disk_total_bytes, 0)',
        };
    }

    /**
     * @param  literal-string  $time
     * @param  literal-string  $aggregate  one or more aggregates; the last is named $alias
     * @param  literal-string|null  $group
     * @param  literal-string  $alias
     * @return list<array<string, mixed>>
     */
    private function rows(Builder $query, string $time, string $aggregate, ?string $group, MetricWindow $window, bool $bucketed = true, string $alias = 'value'): array
    {
        $size = $window->bucketSeconds;
        $query = clone $query;

        if ($bucketed) {
            $query->selectRaw("FLOOR(EXTRACT(EPOCH FROM {$time}) / ?) * ? AS bucket", [$size, $size])->groupBy('bucket');
        } else {
            $query->selectRaw('0 AS bucket');
        }

        if ($group !== null) {
            $query->selectRaw("{$group} AS series")->groupByRaw($group);
        } else {
            $query->selectRaw('NULL AS series');
        }

        return array_values($query->selectRaw("{$aggregate} AS {$alias}")->get()
            ->map(fn (object $row): array => [...(array) $row, 'bucket' => (int) $row->bucket, 'series' => $row->series !== null ? (string) $row->series : null])
            ->all());
    }

    /**
     * Rows as Grafana-ready points, oldest first: count metrics get a zero for empty
     * buckets, a split keeps its busiest series.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{t: string, series: string, value: float|null}>
     */
    private function points(Metric $metric, array $rows, MetricWindow $window, bool $split, int $top): array
    {
        $bySeries = [];

        foreach ($rows as $row) {
            $name = $split ? (($row['series'] ?? '') !== '' ? (string) $row['series'] : self::NONE) : $metric->value;
            $bySeries[$name][(int) $row['bucket']] = $row['value'] !== null ? $this->round($metric, (float) $row['value']) : null;
        }

        if (! $split && $bySeries === [] && $metric->isCount()) {
            $bySeries[$metric->value] = [];
        }

        uasort($bySeries, fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));
        $bySeries = array_slice($bySeries, 0, max(1, $top), true);
        $points = [];

        foreach ($window->buckets() as $bucket) {
            foreach ($bySeries as $name => $values) {
                if (! array_key_exists($bucket, $values) && ! $metric->isCount()) {
                    continue;
                }

                $points[] = ['t' => gmdate('Y-m-d\TH:i:s\Z', $bucket), 'series' => (string) $name, 'value' => $values[$bucket] ?? 0.0];
            }
        }

        return $points;
    }

    /**
     * The column a split reads, in tables that store it under the split's name.
     *
     * @return literal-string|null
     */
    private function column(?MetricGroup $group, bool $nullableEnvironment = false): ?string
    {
        return match ($group) {
            MetricGroup::Environment => $nullableEnvironment ? "COALESCE(environment, '')" : 'environment',
            MetricGroup::Route => 'route',
            MetricGroup::Domain => 'domain',
            MetricGroup::Host => 'host',
            MetricGroup::Queue => 'queue',
            MetricGroup::Release => 'release',
            MetricGroup::Check, null => null,
        };
    }

    private function round(Metric $metric, float $value): float
    {
        return round($value, match ($metric->unit()) {
            'ms' => 1,
            'count', 'bytes' => 0,
            default => 4,
        });
    }

    /**
     * Rows of the filter's route, by its URI or its Laravel name. Counts carry no name, so
     * a name finds its URIs through the window's runs, unless the rows are runs ($named).
     *
     * @param  literal-string  $column
     */
    private function matchRoute(Builder $query, string $column, MetricFilter $filter, MetricWindow $window, bool $named = false): Builder
    {
        $value = $filter->route;

        if ($value === null || $value === '') {
            return $query;
        }

        [$operator, $binding] = str_contains($value, '*') ? ['ILIKE', str_replace('*', '%', addcslashes($value, '\\%_'))] : ['=', $value];

        return $query->where(fn (Builder $q) => $q->whereRaw("{$column} {$operator} ?", [$binding])
            ->when($named)->orWhere('route_name', $operator, $binding)
            ->when(! $named)->orWhereIn('route', DB::table('request_runs')->whereIn('project_id', $filter->projectIds)
                ->where('occurred_at', '>=', $window->from)->where('occurred_at', '<', $window->to)
                ->whereNotNull('route')->where('route_name', $operator, $binding)->distinct()->select('route')));
    }

    /**
     * Rows whose column matches the value exactly, or as a pattern where * is anything.
     *
     * @param  literal-string  $column
     */
    private function match(Builder $query, string $column, ?string $value): Builder
    {
        if ($value === null || $value === '') {
            return $query;
        }

        return str_contains($value, '*')
            ? $query->whereRaw("{$column} ILIKE ?", [str_replace('*', '%', addcslashes($value, '\\%_'))])
            : $query->whereRaw("{$column} = ?", [$value]);
    }
}
