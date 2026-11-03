<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Queries;

use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Queries\JobStatisticsQuery;
use App\Domains\Jobs\Queries\QueueStatisticsQuery;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Projects\Queries\AgentMetricsQuery;
use App\Domains\Releases\Queries\ReleaseHealthQuery;
use App\Domains\Shared\Enums\Period;

/**
 * The project pages' tables as the metrics API serves them: a list of typed columns and
 * rows of values, which Grafana's Infinity data source reads as they are. Each table is
 * the same query its page uses. Durations are in milliseconds.
 *
 * Public stats are aggregates only: routes lose their query strings, issues their titles
 * (unless the project shares them) and hosts their names.
 */
class MetricsTablesQuery
{
    public function __construct(
        private readonly RouteStatisticsQuery $routes,
        private readonly JobStatisticsQuery $jobs,
        private readonly QueueStatisticsQuery $queues,
        private readonly ReleaseHealthQuery $releases,
        private readonly AgentMetricsQuery $agents,
    ) {
    }

    /**
     * @return array{columns: list<array{name: string, type: string, unit: string|null}>, rows: list<list<mixed>>}
     */
    public function routes(Project $project, Period $period, ?string $environment, int $limit, bool $public): array
    {
        return self::table(
            ['method' => 'string', 'route' => 'string', 'route_name' => 'string', 'domain' => 'string', 'requests' => 'number:count', 'p50' => 'number:ms', 'p95' => 'number:ms', 'p99' => 'number:ms', 'error_rate' => 'number:ratio', 'apdex' => 'number:score'],
            array_map(fn (array $r): array => [
                $r['method'],
                $public ? strtok($r['route'], '?') : $r['route'],
                $r['route_name'],
                $r['domain'],
                $r['requests'],
                self::ms($r['p50']),
                self::ms($r['p95']),
                self::ms($r['p99']),
                round($r['error_rate'], 4),
                $r['apdex'],
            ], $this->routes->get($project->id, $period, $environment, 'p95', limit: $limit)),
        );
    }

    /**
     * Open issues, most recently seen first.
     *
     * @return array{columns: list<array{name: string, type: string, unit: string|null}>, rows: list<list<mixed>>}
     */
    public function issues(Project $project, int $limit, bool $titles): array
    {
        $issues = Issue::query()->where('project_id', $project->id)->whereOpen()
            ->orderByDesc('last_seen_at')->limit($limit)->get();

        return self::table(
            ['reference' => 'string', 'title' => 'string', 'status' => 'string', 'occurrences' => 'number:count', 'users' => 'number:count', 'first_seen_at' => 'time', 'last_seen_at' => 'time'],
            array_values($issues->map(fn (Issue $i): array => [
                $i->number !== null ? $i->setRelation('project', $project)->reference() : null,
                $titles ? $i->title : null,
                $i->status->value,
                $i->occurrences_count,
                $i->users_count,
                $i->first_seen_at->toIso8601ZuluString(),
                $i->last_seen_at->toIso8601ZuluString(),
            ])->all()),
        );
    }

    /**
     * @return array{columns: list<array{name: string, type: string, unit: string|null}>, rows: list<list<mixed>>}
     */
    public function jobs(Project $project, Period $period, ?string $environment, int $limit): array
    {
        return self::table(
            ['name' => 'string', 'queue' => 'string', 'runs' => 'number:count', 'failed' => 'number:count', 'failure_rate' => 'number:ratio', 'p50_duration' => 'number:ms', 'p95_duration' => 'number:ms', 'p95_wait' => 'number:ms'],
            array_map(fn (array $j): array => [
                $j['name'], $j['queue'], $j['runs'], $j['failed'], round((float) $j['failure_rate'], 4),
                self::ms($j['p50_duration']), self::ms($j['p95_duration']), self::ms($j['p95_wait']),
            ], $this->jobs->get($project->id, $period, $environment, limit: $limit)),
        );
    }

    /**
     * The latest size and oldest age per queue, with the p95 wait of the period's jobs.
     *
     * @return array{columns: list<array{name: string, type: string, unit: string|null}>, rows: list<list<mixed>>}
     */
    public function queues(Project $project, Period $period, ?string $environment): array
    {
        return self::table(
            ['connection' => 'string', 'queue' => 'string', 'size' => 'number:count', 'oldest_age' => 'number:s', 'p95_wait' => 'number:ms', 'sampled_at' => 'time'],
            array_map(fn (array $q): array => [
                $q['connection'], $q['queue'], $q['size'], $q['oldest_age_s'], self::ms($q['p95_wait']),
                $q['sampled_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($q['sampled_at'])) : null,
            ], $this->queues->get($project->id, $period, $environment)),
        );
    }

    /**
     * The latest host metrics per agent. CPU is the load per core, memory and disk the
     * share in use.
     *
     * @return array{columns: list<array{name: string, type: string, unit: string|null}>, rows: list<list<mixed>>}
     */
    public function hosts(Project $project, bool $public): array
    {
        $agents = ProjectAgent::query()->where('project_id', $project->id)->orderBy('host')->get();
        $latest = $this->agents->latest($agents);
        $share = fn (int|float|null $used, int|float|null $total): ?float => $used !== null && $total ? round($used / $total, 4) : null;

        return self::table(
            ['host' => 'string', 'online' => 'boolean', 'last_seen_at' => 'time', 'cpu_load' => 'number:ratio', 'memory_used' => 'number:ratio', 'disk_used' => 'number:ratio', 'memory_used_bytes' => 'number:bytes', 'disk_used_bytes' => 'number:bytes'],
            array_values($agents->values()->map(function (ProjectAgent $agent, int $i) use ($latest, $share, $public): array {
                $m = $latest[$agent->id] ?? null;

                return [
                    $public ? 'host '.($i + 1) : $agent->host,
                    $agent->isOnline(),
                    $agent->last_seen_at->toIso8601ZuluString(),
                    $share($m['cpu_load_1m'] ?? null, $m['cpu_cores'] ?? null),
                    $share($m['memory_used_bytes'] ?? null, $m['memory_total_bytes'] ?? null),
                    $share($m['disk_used_bytes'] ?? null, $m['disk_total_bytes'] ?? null),
                    $m['memory_used_bytes'] ?? null,
                    $m['disk_used_bytes'] ?? null,
                ];
            })->all()),
        );
    }

    /**
     * @return array{columns: list<array{name: string, type: string, unit: string|null}>, rows: list<list<mixed>>}
     */
    public function releases(Project $project, Period $period, ?string $environment, int $limit): array
    {
        return self::table(
            ['release' => 'string', 'first_seen_at' => 'time', 'last_seen_at' => 'time', 'requests' => 'number:count', 'error_rate' => 'number:ratio', 'p95' => 'number:ms', 'occurrences' => 'number:count', 'new_issues' => 'number:count', 'regressions' => 'number:count'],
            array_map(fn (array $r): array => [
                $r['release'], $r['first_seen_at'], $r['last_seen_at'], $r['requests'],
                $r['error_rate'] !== null ? round($r['error_rate'], 4) : null, self::ms($r['p95']),
                $r['occurrences'], $r['new_issues'], $r['regressions'],
            ], array_slice($this->releases->get($project->id, $period, $environment), 0, $limit)),
        );
    }

    /**
     * @param  array<string, string>  $columns  name => type, or type:unit
     * @param  list<list<mixed>>  $rows
     * @return array{columns: list<array{name: string, type: string, unit: string|null}>, rows: list<list<mixed>>}
     */
    public static function table(array $columns, array $rows): array
    {
        $described = [];

        foreach ($columns as $name => $type) {
            [$kind, $unit] = [...explode(':', $type, 2), null];
            $described[] = ['name' => $name, 'type' => $kind, 'unit' => $unit];
        }

        return ['columns' => $described, 'rows' => $rows];
    }

    private static function ms(?int $microseconds): ?float
    {
        return $microseconds !== null ? round($microseconds / 1000, 1) : null;
    }
}
