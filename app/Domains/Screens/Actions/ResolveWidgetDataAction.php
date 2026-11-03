<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Alerts\Data\QueueBacklogSettings;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckHost;
use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Issues\Actions\DetectEscalationAction;
use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Jobs\Queries\QueueStatisticsQuery;
use App\Domains\Metrics\Data\MetricFilter;
use App\Domains\Metrics\Data\MetricWindow;
use App\Domains\Metrics\Enums\Metric;
use App\Domains\Metrics\Enums\MetricGroup;
use App\Domains\Metrics\Queries\MetricSeriesQuery;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Projects\Queries\AgentMetricsQuery;
use App\Domains\Releases\Queries\ReleaseHealthQuery;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Screens\Data\WidgetScope;
use App\Domains\Screens\Data\WidgetSource;
use App\Domains\Screens\Enums\ReleaseScope;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Support\WidgetSources;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The data one status screen widget shows, as JSON: what its source (WidgetSources)
 * reads over its scope, within the team's projects only. Read only; the figures come
 * from the same queries as the metrics API and the project pages, so a screen, Grafana
 * and the pages agree. See the payload shapes on each method.
 *
 * Options read here (all optional): amber, red, low_is_bad (limits), compare
 * (previous, yesterday, last_week, none) and sparkline for a stat; min and max for a
 * gauge; split_by (a metric group or project), top and overlay_previous for a chart;
 * limit, sort (newest, most_frequent, last_seen, worst) and only_problems for a list; checks (uuids) and
 * only_problems for health. Display options are the widget's own business.
 */
class ResolveWidgetDataAction
{
    /** @var array<int, AlertRule|false> the queue backlog rule per project, false for none */
    private array $backlogRules = [];

    public const int LIST_LIMIT = 10;

    public function __construct(
        private readonly MetricSeriesQuery $metrics,
        private readonly RouteStatisticsQuery $routes,
        private readonly QueueStatisticsQuery $queues,
        private readonly ReleaseHealthQuery $releases,
        private readonly AgentMetricsQuery $agents,
        private readonly HealthChecksQuery $health,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  bool  $aggregatesOnly  for a shared screen: no issue titles or host names
     * @return array<string, mixed>
     */
    public function execute(Team $team, WidgetType $type, string $sourceId, WidgetScope $scope, array $options = [], bool $aggregatesOnly = false): array
    {
        $source = WidgetSources::find($type, $sourceId);
        // The team's projects load once per team instance: a screen asks for every widget.
        $projects = $team->projects
            ->when($scope->projects !== [])->whereIn('uuid', $scope->projects)
            ->sortBy('name')->values();
        $limits = [
            'amber' => $this->number($options['amber'] ?? null) ?? $source->amber,
            'red' => $this->number($options['red'] ?? null) ?? $source->red,
            'low_is_bad' => (bool) ($options['low_is_bad'] ?? $source->lowIsBad),
        ];

        $data = match ($type) {
            WidgetType::Stat => $this->stat($source, $projects, $scope, $options, $limits),
            WidgetType::Gauge => $this->gauge($source, $projects, $scope, $options, $limits),
            WidgetType::Chart => $this->chart($source, $projects, $scope, $options),
            WidgetType::List => $this->listRows($source, $projects, $scope, $options, $limits, $aggregatesOnly),
            WidgetType::Health => $this->checks($projects, $scope, $options),
        };

        // The period it covers, for the tile's corner label; health and the latest report are now.
        $period = $type === WidgetType::Health || $source->snapshot ? 'now' : $scope->period->value;

        return ['type' => $type->value, 'source' => $source->id, 'unit' => $source->unit, 'period' => $period, 'as_of' => $period === 'now' ? $this->asOf($source, $projects) : null, ...$data];
    }

    /**
     * {value, previous, change, state, limits, sparkline}: value is a number (a string
     * for current_release), previous the same over the compared window, change the
     * relative difference, sparkline a list of {t, v} or null.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     * @param  array{amber: float|null, red: float|null, low_is_bad: bool}  $limits
     * @return array<string, mixed>
     */
    private function stat(WidgetSource $source, Collection $projects, WidgetScope $scope, array $options, array $limits): array
    {
        $window = MetricWindow::forPeriod($scope->period);
        $previous = null;
        $sparkline = null;
        $release = null;

        if ($source->metric !== null) {
            $filter = $this->filter($source, $projects, $scope);
            $value = $this->metrics->value($source->metric, $filter, $window);
            $compared = match ($options['compare'] ?? 'previous') {
                'none' => null,
                'yesterday' => $window->shift(86400),
                'last_week' => $window->shift(7 * 86400),
                default => $window->previous(),
            };
            $previous = $compared !== null ? $this->metrics->value($source->metric, $filter, $compared) : null;
            $sparkline = ($options['sparkline'] ?? true) ? $this->line($this->metrics->series($source->metric, $filter, $window)) : null;
        } else {
            $value = match ($source->id) {
                'open_issues' => (float) Issue::query()->whereIn('project_id', $projects->modelKeys())->whereOpen()->count(),
                'agents_online' => (float) $this->agentQuery($projects, $scope)->where('last_seen_at', '>', now()->subMinutes(ProjectAgent::OFFLINE_AFTER_MINUTES))->count(),
                'current_release' => ($release = $projects->isNotEmpty() ? ($this->releases->get($projects->first()->id, $scope->period, $scope->environment)[0] ?? null) : null)['release'] ?? null,
                default => null,
            };
        }

        return [
            'value' => $value,
            'previous' => $previous,
            'change' => is_float($value) && $previous !== null && $previous != 0 ? round($value / $previous - 1, 4) : null,
            'state' => is_float($value) ? $this->state($value, $limits) : null,
            'limits' => $limits,
            'sparkline' => $sparkline,
            // The current release says when it arrived and how it compares with the one before.
            ...($release !== null ? ['release' => [
                'since' => $release['first_seen_at'],
                'previous' => $release['previous']['release'] ?? null,
                'error_rate' => $release['error_rate'],
                'error_rate_change' => $release['change']['error_rate'],
            ]] : []),
        ];
    }

    /**
     * {value, min, max, state, limits}.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     * @param  array{amber: float|null, red: float|null, low_is_bad: bool}  $limits
     * @return array<string, mixed>
     */
    private function gauge(WidgetSource $source, Collection $projects, WidgetScope $scope, array $options, array $limits): array
    {
        $value = $source->metric !== null
            ? $this->metrics->value($source->metric, $this->filter($source, $projects, $scope), MetricWindow::forPeriod($scope->period))
            : $this->lowestUptime($projects, $scope, $options);
        $bounded = in_array($source->unit, ['ratio', 'score'], true);

        return [
            'value' => $value,
            'min' => $this->number($options['min'] ?? null) ?? 0.0,
            'max' => $this->number($options['max'] ?? null) ?? ($bounded ? 1.0 : max(1.0, ($limits['red'] ?? 0) * 1.5, $value ?? 0)),
            'state' => $value !== null ? $this->state($value, $limits) : null,
            'limits' => $limits,
        ];
    }

    /**
     * {interval, series: [{name, points: [{t, v}]}], red}: one series, or one per value
     * of split_by (busiest first); overlay_previous adds "previous", moved onto the
     * period's own times.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function chart(WidgetSource $source, Collection $projects, WidgetScope $scope, array $options): array
    {
        $window = MetricWindow::forPeriod($scope->period);
        $metric = $source->metric ?? Metric::Requests;
        $top = max(1, min(20, (int) ($options['top'] ?? 5)));
        $split = is_string($options['split_by'] ?? null) ? $options['split_by'] : null;
        $series = [];

        if ($split === 'project') {
            foreach ($projects as $project) {
                $series[$project->name] = $this->line($this->metrics->series($metric, $this->filter($source, new Collection([$project]), $scope), $window));
            }

            uasort($series, fn (array $a, array $b): int => array_sum(array_column($b, 'v')) <=> array_sum(array_column($a, 'v')));
            $series = array_slice($series, 0, $top, true);
        } else {
            $filter = $this->filter($source, $projects, $scope, MetricGroup::tryFrom((string) $split));

            foreach ($this->metrics->series($metric, $filter, $window, $top) as $point) {
                $series[$point['series']][] = ['t' => $point['t'], 'v' => $point['value']];
            }

            if ($series === [] && $metric->isCount()) {
                $series[$metric->value] = [];
            }
        }

        if ($source->id === 'requests_errors') {
            $series = [
                'requests' => array_values($series)[0] ?? [],
                'errors' => $this->line($this->metrics->series(Metric::Errors, $this->filter($source, $projects, $scope), $window)),
            ];
        }

        if (($options['overlay_previous'] ?? false) && $split === null) {
            $shift = $window->to->getTimestamp() - $window->from->getTimestamp();
            $series['previous'] = array_map(
                fn (array $point): array => ['t' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($point['t']) + $shift), 'v' => $point['v']],
                $this->line($this->metrics->series($metric, $this->filter($source, $projects, $scope), $window->previous())),
            );
        }

        return [
            'interval' => $window->interval(),
            'red' => $this->number($options['red'] ?? null) ?? $source->red,
            'series' => array_map(fn (string|int $name, array $points): array => ['name' => (string) $name, 'points' => $points], array_keys($series), array_values($series)),
        ];
    }

    /**
     * {columns, rows}: rows are objects keyed by the columns, each with the project's
     * slug and a state (ok, amber, red or null) where the source has one.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     * @param  array{amber: float|null, red: float|null, low_is_bad: bool}  $limits
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function listRows(WidgetSource $source, Collection $projects, WidgetScope $scope, array $options, array $limits, bool $aggregatesOnly): array
    {
        $limit = max(1, min(100, (int) ($options['limit'] ?? self::LIST_LIMIT)));
        $onlyProblems = (bool) ($options['only_problems'] ?? false);
        $slugs = $projects->pluck('slug', 'id');
        $since = $scope->period->since();
        $ids = $projects->modelKeys();

        [$columns, $rows] = match ($source->id) {
            'latest_issues' => [
                ['reference', 'title', 'status', 'occurrences', 'users', 'last_seen_at'],
                Issue::query()->whereIn('project_id', $ids)->whereOpen()
                    ->when($onlyProblems)->whereNotNull('escalated_at')
                    ->when(($options['sort'] ?? 'newest') === 'most_frequent')->orderByDesc('occurrences_count')
                    ->when(($options['sort'] ?? 'newest') === 'last_seen')->orderByDesc('last_seen_at')
                    // Most important first: escalating now, then regressed, then the newest.
                    ->when(($options['sort'] ?? 'newest') === 'worst')->orderByRaw(
                        'CASE WHEN escalated_at > ? THEN 0 WHEN regressed_at IS NOT NULL THEN 1 ELSE 2 END',
                        [now()->subMinutes(DetectEscalationAction::ACTIVE_MINUTES)],
                    )
                    ->latest('surfaced_at')->limit($limit)->get()
                    ->map(fn (Issue $i): array => [
                        'project' => $slugs[$i->project_id],
                        'reference' => $i->number !== null ? $i->setRelation('project', $projects->find($i->project_id))->reference() : null,
                        'title' => $aggregatesOnly ? null : $i->title,
                        'status' => $i->status->value,
                        'occurrences' => $i->occurrences_count,
                        'users' => $i->users_count,
                        'last_seen_at' => $i->last_seen_at->toIso8601String(),
                        'state' => $i->isEscalating() ? 'red' : ($i->isRegressed() ? 'amber' : null),
                    ])->all(),
            ],
            'alert_feed' => [
                ['sent_at', 'issue', 'channel', 'status'],
                DB::table('alert_sends as s')
                    ->join('issues as i', 'i.id', '=', 's.issue_id')
                    ->join('notification_channels as c', 'c.id', '=', 's.notification_channel_id')
                    ->whereIn('i.project_id', $ids)
                    ->where('s.sent_at', '>=', $since)
                    ->when($onlyProblems)->where('s.status', '!=', 'sent')
                    ->orderByDesc('s.sent_at')->limit($limit)
                    ->get(['s.sent_at', 's.status', 'i.project_id', 'i.title', 'c.name as channel'])
                    ->map(fn (object $row): array => (array) $row)
                    ->map(fn (array $r): array => [
                        'project' => $slugs[$r['project_id']],
                        'sent_at' => date(DATE_ATOM, (int) strtotime((string) $r['sent_at'])),
                        'issue' => $aggregatesOnly ? null : $r['title'],
                        'channel' => $r['channel'],
                        'status' => $r['status'],
                        'state' => $r['status'] === 'sent' ? null : 'red',
                    ])->all(),
            ],
            'failed_jobs' => [
                ['name', 'failures', 'last_failed_at'],
                $this->pattern(DB::table('job_runs')->whereIn('project_id', $ids)
                    ->where('created_at', '>=', $since)
                    ->where('status', JobRunStatus::Failed->value)
                    ->when($scope->environment !== null)->where('environment', $scope->environment), 'name', $scope->filter)
                    ->groupBy('project_id', 'name')
                    ->selectRaw('project_id, name, COUNT(*) AS failures, MAX(updated_at) AS last_failed_at')
                    ->orderByDesc('failures')->limit($limit)->get()
                    ->map(fn (object $r): array => [
                        'project' => $slugs[$r->project_id],
                        'name' => $r->name,
                        'failures' => (int) $r->failures,
                        'last_failed_at' => date(DATE_ATOM, (int) strtotime((string) $r->last_failed_at)),
                        'state' => 'red',
                    ])->all(),
            ],
            'scheduled_tasks' => [
                ['name', 'status', 'last_run_at', 'next_run_at'],
                ScheduledTask::query()->whereIn('project_id', $ids)
                    ->when($scope->environment !== null)->where('environment', $scope->environment)
                    ->when($scope->filter !== null)->where('name', 'ILIKE', str_replace('*', '%', addcslashes((string) $scope->filter, '\\%_')))
                    ->when($onlyProblems)->whereNeedsAttention()
                    ->orderByRaw("CASE last_status WHEN 'failed' THEN 0 WHEN 'missed' THEN 1 ELSE 2 END")->orderBy('name')
                    ->limit($limit)->get()
                    ->map(fn (ScheduledTask $t): array => [
                        'project' => $slugs[$t->project_id],
                        'name' => $t->name,
                        'status' => $t->last_status?->value,
                        'last_run_at' => $t->last_run_at?->toIso8601String(),
                        'next_run_at' => $t->next_run_at?->toIso8601String(),
                        'state' => match ($t->last_status?->value) {
                            'failed' => 'red',
                            'missed' => 'amber',
                            null => null,
                            default => 'ok',
                        },
                    ])->all(),
            ],
            'slowest_routes' => [
                ['method', 'route', 'p95', 'requests', 'error_rate'],
                collect($projects)->flatMap(fn (Project $p): array => array_map(
                    fn (array $r): array => [
                        'project' => $p->slug,
                        'method' => $r['method'],
                        'route' => strtok($r['route'], '?'),
                        'p95' => $r['p95'] !== null ? round($r['p95'] / 1000, 1) : null,
                        'requests' => $r['requests'],
                        'error_rate' => round($r['error_rate'], 4),
                        'state' => $r['p95'] !== null ? $this->state($r['p95'] / 1000, $limits) : null,
                    ],
                    $this->routes->get($p->id, $scope->period, $scope->environment, 'p95', $scope->filter, $limit),
                ))->sortByDesc('p95')->when($onlyProblems)->filter(fn (array $r): bool => in_array($r['state'], ['amber', 'red'], true))->take($limit)->values()->all(),
            ],
            'queues' => [
                ['queue', 'connection', 'size', 'oldest_age_s'],
                collect($projects)->flatMap(fn (Project $p): array => array_map(
                    fn (array $q): array => [
                        'project' => $p->slug,
                        'queue' => $q['queue'],
                        'connection' => $q['connection'],
                        'size' => $q['size'],
                        'oldest_age_s' => $q['oldest_age_s'],
                        ...$this->queueState($p, $q, $options, $limits),
                    ],
                    $this->queues->get($p->id, $scope->period, $scope->environment),
                ))->when($scope->filter !== null)->filter(fn (array $q): bool => fnmatch((string) $scope->filter, $q['queue']))
                    ->sortByDesc('size')->when($onlyProblems)->filter(fn (array $r): bool => in_array($r['state'], ['amber', 'red'], true))->take($limit)->values()->all(),
            ],
            'hosts', 'agents' => $this->agentRows($source, $projects, $scope, $limits, $limit, $onlyProblems, $aggregatesOnly),
            default => [[], []],
        };

        return ['columns' => $columns, 'rows' => array_values($rows)];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  array{amber: float|null, red: float|null, low_is_bad: bool}  $limits
     * @return array{list<string>, list<array<string, mixed>>}
     */
    private function agentRows(WidgetSource $source, Collection $projects, WidgetScope $scope, array $limits, int $limit, bool $onlyProblems, bool $aggregatesOnly): array
    {
        $agents = $this->agentQuery($projects, $scope)->orderBy('host')->get();
        $slugs = $projects->pluck('slug', 'id');
        $latest = $source->id === 'hosts' ? $this->agents->latest($agents) : [];
        $share = fn (int|float|null $used, int|float|null $total): ?float => $used !== null && $total ? round($used / $total, 4) : null;

        $rows = $agents->values()->map(function (ProjectAgent $a, int $i) use ($source, $latest, $share, $slugs, $limits, $aggregatesOnly): array {
            $host = $aggregatesOnly ? 'host '.($i + 1) : $a->host;

            if ($source->id === 'agents') {
                return ['project' => $slugs[$a->project_id], 'host' => $host, 'name' => $aggregatesOnly ? null : ($a->name !== '' ? $a->name : null), 'last_seen_at' => $a->last_seen_at->toIso8601String(), 'online' => $a->isOnline(), 'state' => $a->isOnline() ? 'ok' : 'red'];
            }

            $m = $latest[$a->id] ?? null;
            $cpu = $share($m['cpu_load_1m'] ?? null, $m['cpu_cores'] ?? null);
            $memory = $share($m['memory_used_bytes'] ?? null, $m['memory_total_bytes'] ?? null);
            $disk = $share($m['disk_used_bytes'] ?? null, $m['disk_total_bytes'] ?? null);
            $worst = max($cpu ?? 0, $memory ?? 0, $disk ?? 0);

            return ['project' => $slugs[$a->project_id], 'host' => $host, 'cpu_load' => $cpu, 'memory_used' => $memory, 'disk_used' => $disk, 'online' => $a->isOnline(), 'state' => $a->isOnline() ? $this->state($worst, $limits) : 'red'];
        });

        // A host is one machine however many agents ran on it: keep its online agent with
        // numbers, so an old agent of the same host does not show it as offline twice.
        if ($source->id === 'hosts') {
            $rows = $rows->groupBy(fn (array $r): string => $r['project'].'|'.$r['host'])
                ->map(fn ($same): array => $same->sortByDesc(fn (array $r): int => ($r['online'] ? 2 : 0) + (($r['cpu_load'] ?? null) !== null ? 1 : 0))->first())
                ->values();
        }

        $columns = $source->id === 'agents' ? ['host', 'name', 'last_seen_at', 'online'] : ['host', 'cpu_load', 'memory_used', 'disk_used'];

        return [$columns, $rows->when($onlyProblems)->filter(fn (array $r): bool => in_array($r['state'], ['amber', 'red'], true))->take($limit)->values()->all()];
    }

    /**
     * {summary: {total, ok, degraded, down, recovering, stale, pending}, checks: [{uuid,
     * project, name, state, message}], check}: worst first. With exactly one check picked,
     * check is its detail: {uuid, name, state, last_message, last_result_at, duration_us,
     * uptime, strip: [{t, s}], since}, else null.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function checks(Collection $projects, WidgetScope $scope, array $options): array
    {
        $picked = array_values(array_filter((array) ($options['checks'] ?? []), 'is_string'));
        $states = ['total' => 0, 'ok' => 0, 'degraded' => 0, 'down' => 0, 'recovering' => 0, 'stale' => 0, 'pending' => 0];
        $checks = [];

        foreach ($projects as $project) {
            foreach ($this->health->summary($project, $scope->environment)['checks'] as $check) {
                if ($picked !== [] && ! in_array($check['uuid'], $picked, true)) {
                    continue;
                }

                $states['total']++;
                $states[$check['state']]++;

                if (! ($options['only_problems'] ?? false) || $check['state'] !== 'ok') {
                    $checks[] = ['project' => $project->slug, ...$check];
                }
            }
        }

        $severity = ['down', 'degraded', 'recovering', 'stale', 'pending', 'ok'];
        usort($checks, fn (array $a, array $b): int => array_search($a['state'], $severity, true) <=> array_search($b['state'], $severity, true));
        $detail = null;

        if (count($picked) === 1 && count($checks) === 1) {
            $project = $projects->firstWhere('slug', $checks[0]['project']);
            $row = collect($this->health->rows($project, $scope->period, $scope->environment))->firstWhere('uuid', $picked[0]);
            $detail = $row !== null ? [...array_intersect_key($row, array_flip(['uuid', 'name', 'state', 'last_message', 'last_result_at', 'duration_us', 'uptime', 'strip'])), 'since' => $checks[0]['since'] ?? null] : null;
        }

        return ['summary' => $states, 'checks' => $checks, 'check' => $detail];
    }

    /**
     * The lowest uptime of the scope's checks over the period (or of the picked ones).
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     */
    private function lowestUptime(Collection $projects, WidgetScope $scope, array $options): ?float
    {
        $picked = array_values(array_filter((array) ($options['checks'] ?? []), 'is_string'));

        $uptimes = $projects->toBase()->flatMap(fn (Project $p): array => $this->health->rows($p, $scope->period, $scope->environment))
            ->filter(fn (array $row): bool => ! $row['archived'] && $row['uptime'] !== null && ($picked === [] || in_array($row['uuid'], $picked, true)))
            ->pluck('uptime');

        return $uptimes->isNotEmpty() ? (float) $uptimes->min() : null;
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    private function filter(WidgetSource $source, Collection $projects, WidgetScope $scope, ?MetricGroup $group = null): MetricFilter
    {
        $on = fn (string $dimension): ?string => $source->filterOn === $dimension ? $scope->filter : null;

        return new MetricFilter(
            projectIds: array_values(array_map(intval(...), $projects->modelKeys())),
            environment: $scope->environment,
            route: $on('route'),
            queue: $on('queue'),
            job: $on('job'),
            host: $on('host'),
            release: $this->release($projects, $scope),
            groupBy: $group,
            check: $on('check'),
        );
    }

    /**
     * The release the scope asks for. Current and previous are the newest releases seen
     * in the period, which only means something for one project: across several the
     * scope covers every release. Without such a release the figures cover none.
     *
     * @param  Collection<int, Project>  $projects
     */
    private function release(Collection $projects, WidgetScope $scope): ?string
    {
        if ($scope->release === ReleaseScope::All || $projects->count() !== 1) {
            return null;
        }

        $releases = $this->releases->get($projects->first()->id, $scope->period, $scope->environment);

        // An empty release matches no row: there is nothing to show yet.
        return $releases[$scope->release === ReleaseScope::Current ? 0 : 1]['release'] ?? '';
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return \Illuminate\Database\Eloquent\Builder<ProjectAgent>
     */
    private function agentQuery(Collection $projects, WidgetScope $scope): \Illuminate\Database\Eloquent\Builder
    {
        return ProjectAgent::query()->whereIn('project_id', $projects->modelKeys())
            ->when($scope->filter !== null)->where('host', 'ILIKE', str_replace('*', '%', addcslashes((string) $scope->filter, '\\%_')));
    }

    /**
     * @param  list<array{t: string, series: string, value: float|null}>  $points
     * @return list<array{t: string, v: float|null}>
     */
    private function line(array $points): array
    {
        return array_map(fn (array $p): array => ['t' => $p['t'], 'v' => $p['value']], $points);
    }

    /**
     * When the latest report behind a snapshot value arrived: the newest queue sample, host
     * sample, agent heartbeat, health result or database check result. Null for values worked out on the spot.
     *
     * @param  Collection<int, Project>  $projects
     */
    private function asOf(WidgetSource $source, Collection $projects): ?string
    {
        $ids = $projects->modelKeys();
        $at = match (true) {
            in_array($source->id, ['queue_size', 'queues'], true) => QueueSample::query()->whereIn('project_id', $ids)->max('sampled_at'),
            str_starts_with($source->id, 'host_') || $source->id === 'hosts' => HostSample::query()
                ->whereIn('project_agent_id', ProjectAgent::query()->whereIn('project_id', $ids)->select('id'))->max('sampled_at'),
            in_array($source->id, ['agents', 'agents_online'], true) => ProjectAgent::query()->whereIn('project_id', $ids)->max('last_seen_at'),
            $source->type === WidgetType::Health => HealthCheckHost::query()
                ->whereIn('health_check_id', HealthCheck::query()->whereIn('project_id', $ids)->select('id'))->max('last_result_at'),
            str_starts_with($source->id, 'db_') => HealthCheckHost::query()
                ->whereIn('health_check_id', HealthCheck::query()->whereIn('project_id', $ids)->whereReportsDatabase()->select('id'))->max('last_result_at'),
            default => null,
        };

        return $at !== null ? CarbonImmutable::parse((string) $at)->toIso8601String() : null;
    }

    /**
     * A queue's state and why. Limits set on the widget count as given (on its size).
     * Otherwise the queue is judged like its backlog alert: red past the project's alert
     * thresholds (its own for that queue, or the rule's), amber past half of them.
     *
     * @param  array{queue: string, connection: string, size: int|null, oldest_age_s: int|null}  $q
     * @param  array<string, mixed>  $options
     * @param  array{amber: float|null, red: float|null, low_is_bad: bool}  $limits
     * @return array{state: string|null, reason: string|null}
     */
    private function queueState(Project $project, array $q, array $options, array $limits): array
    {
        if ($this->number($options['amber'] ?? null) !== null || $this->number($options['red'] ?? null) !== null) {
            return ['state' => $q['size'] !== null ? $this->state((float) $q['size'], $limits) : null, 'reason' => 'size'];
        }

        $rule = $this->backlogRules[$project->id] ??= AlertRule::query()->whereEffectiveFor($project)->whereType(AlertRuleType::QueueBacklog)->first() ?? false;
        $settings = $rule instanceof AlertRule
            ? QueueBacklogSettings::forQueue($rule, $project, $q['connection'], $q['queue'])
            : new QueueBacklogSettings();

        if ($settings->muted) {
            return ['state' => 'ok', 'reason' => null];
        }

        $size = $q['size'] ?? 0;
        $age = $q['oldest_age_s'] ?? 0;
        $past = fn (float $share): ?string => match (true) {
            $settings->size !== null && $size >= $settings->size * $share => 'size',
            $settings->ageMinutes !== null && $age >= $settings->ageMinutes * 60 * $share => 'age',
            default => null,
        };

        return ($reason = $past(1.0)) !== null
            ? ['state' => 'red', 'reason' => $reason]
            : (($reason = $past(0.5)) !== null ? ['state' => 'amber', 'reason' => $reason] : ['state' => 'ok', 'reason' => null]);
    }

    /**
     * @param  array{amber: float|null, red: float|null, low_is_bad: bool}  $limits
     */
    private function state(float $value, array $limits): ?string
    {
        if ($limits['amber'] === null && $limits['red'] === null) {
            return null;
        }

        $past = fn (?float $limit): bool => $limit !== null && ($limits['low_is_bad'] ? $value <= $limit : $value >= $limit);

        return $past($limits['red']) ? 'red' : ($past($limits['amber']) ? 'amber' : 'ok');
    }

    private function pattern(\Illuminate\Database\Query\Builder $query, string $column, ?string $value): \Illuminate\Database\Query\Builder
    {
        return $value === null ? $query : $query->where($column, 'ILIKE', str_replace('*', '%', addcslashes($value, '\\%_')));
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
