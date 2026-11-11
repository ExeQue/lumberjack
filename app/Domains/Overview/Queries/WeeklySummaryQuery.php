<?php

declare(strict_types=1);

namespace App\Domains\Overview\Queries;

use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthOutage;
use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Models\Issue;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Metrics\Data\MetricFilter;
use App\Domains\Metrics\Data\MetricWindow;
use App\Domains\Metrics\Enums\Metric;
use App\Domains\Metrics\Queries\MetricSeriesQuery;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Releases\Queries\ReleaseHealthQuery;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * A project's past 7 days against the 7 before, for the weekly summary alert: the
 * figures the overview, the metrics API and the Releases and Health pages read, through
 * their own queries. Durations in milliseconds, rates as a share of 1.
 */
class WeeklySummaryQuery
{
    /** How many issues, routes and outages a summary lists. */
    public const int LISTED = 5;

    /** The busiest routes whose p95 is compared with the week before. */
    private const int ROUTES_COMPARED = 20;

    public function __construct(
        private readonly MetricSeriesQuery $metrics,
        private readonly RouteStatisticsQuery $routes,
        private readonly HealthChecksQuery $health,
        private readonly ReleaseHealthQuery $releases,
    ) {
    }

    /**
     * @return array<string, mixed>|null  null when nothing came in for the project all week
     */
    public function get(Project $project): ?array
    {
        $week = MetricWindow::forPeriod(Period::Week);
        $before = $week->previous();
        $filter = new MetricFilter([$project->id]);
        $compare = fn (Metric $metric): array => ['current' => $this->metrics->value($metric, $filter, $week), 'previous' => $this->metrics->value($metric, $filter, $before)];
        $count = fn (callable $query): array => ['current' => $query($week), 'previous' => $query($before)];

        $requests = $compare(Metric::Requests);
        $occurrences = (int) $this->metrics->value(Metric::Occurrences, $filter, $week);
        $jobRuns = (int) $this->metrics->value(Metric::JobRuns, $filter, $week);
        $scheduled = ScheduledTaskRun::query()->where('project_id', $project->id)->whereBetween('started_at', [$week->from, $week->to])->count();
        $outages = HealthOutage::query()->where('project_id', $project->id)->whereBetween('started_at', [$week->from, $week->to])->orderBy('started_at')->get();
        $windows = MaintenanceWindow::query()->where('project_id', $project->id)->whereOverlaps($week->from)->orderBy('starts_at')->get();
        $checked = HealthCheck::query()->where('project_id', $project->id)->where('last_result_at', '>=', $week->from)->exists();

        if ($requests['current'] == 0 && $occurrences === 0 && $jobRuns === 0 && $scheduled === 0 && $outages->isEmpty() && $windows->isEmpty() && ! $checked) {
            return null;
        }

        $checks = HealthCheck::query()->whereKey($outages->pluck('health_check_id')->unique()->all())->get()->keyBy('id');
        // The releases first seen in the week, with what they brought from the Releases page's figures.
        $health = collect($this->releases->get($project->id, Period::Week))->keyBy('release');
        $releases = DB::table('project_releases')->where('project_id', $project->id)
            ->where('first_seen_at', '>=', $week->from)->where('first_seen_at', '<', $week->to)->orderBy('first_seen_at')->get()
            ->map(fn (object $row): array => [
                'release' => (string) $row->release,
                'first_seen_at' => CarbonImmutable::parse($row->first_seen_at)->toIso8601String(),
                'new_issues' => (int) ($health->get($row->release)['new_issues'] ?? 0),
                'regressions' => (int) ($health->get($row->release)['regressions'] ?? 0),
            ])->all();
        $worst = collect($releases)->where('new_issues', '>', 0)->sortByDesc('new_issues')->first();

        return [
            'project' => ['id' => $project->uuid, 'slug' => $project->slug, 'name' => $project->name, 'url' => route('projects.show', $project)],
            'requests' => array_map(intval(...), $requests),
            'error_rate' => $compare(Metric::ErrorRate),
            'p95_ms' => $compare(Metric::P95),
            'apdex' => $compare(Metric::Apdex),
            'new_issues' => array_map(intval(...), $compare(Metric::NewIssues)),
            'regressions' => $count(fn (MetricWindow $w): int => DB::table('issue_events')->join('issues', 'issues.id', '=', 'issue_events.issue_id')
                ->where('issues.project_id', $project->id)->where('issue_events.type', IssueEventType::Regressed->value)
                ->where('issue_events.occurred_at', '>=', $w->from)->where('issue_events.occurred_at', '<', $w->to)->count()),
            'top_issues' => $this->topIssues($project, $week),
            'slower_routes' => $this->slowerRoutes($project, $filter, $before),
            'failed_jobs' => array_map(intval(...), $compare(Metric::JobFailures)),
            'missed_tasks' => $count(fn (MetricWindow $w): int => ScheduledTaskRun::query()->where('project_id', $project->id)
                ->where('status', ScheduledRunStatus::Missed)->where('started_at', '>=', $w->from)->where('started_at', '<', $w->to)->count()),
            'health_checks' => collect($this->health->rows($project, Period::Week))
                ->filter(fn (array $row): bool => ! $row['archived'] && $row['uptime'] !== null)
                ->map(fn (array $row): array => Arr::only($row, ['name', 'environment', 'uptime']))
                ->values()->all(),
            'outages' => [
                'current' => $outages->count(),
                'previous' => HealthOutage::query()->where('project_id', $project->id)->where('started_at', '>=', $before->from)->where('started_at', '<', $before->to)->count(),
                'down' => $outages->where('status', HealthStatus::Down)->count(),
                'listed' => array_values($outages->take(self::LISTED)->map(fn (HealthOutage $outage): array => [
                    'check' => $checks->get($outage->health_check_id)?->displayName(),
                    'status' => $outage->status->value,
                    'started_at' => $outage->started_at->toIso8601String(),
                    'ended_at' => $outage->ended_at?->toIso8601String(),
                ])->all()),
            ],
            'maintenance' => array_values($windows->map(fn (MaintenanceWindow $window): array => [
                ...$window->toSummary(),
                // Minutes it ran past its expected end, up to its end or now; 0 within it.
                'overrun_minutes' => max(0, (int) floor($window->expected_end_at->diffInMinutes($window->ended_at ?? now(), false))),
            ])->all()),
            'releases' => $releases,
            'most_new_issues' => $worst !== null ? ['release' => $worst['release'], 'new_issues' => $worst['new_issues']] : null,
        ];
    }

    /**
     * The issues that happened most in the week, by their occurrences in it.
     *
     * @return list<array{reference: string|null, title: string, occurrences: int, url: string}>
     */
    private function topIssues(Project $project, MetricWindow $week): array
    {
        $counts = DB::table('occurrences')->where('project_id', $project->id)
            ->where('occurred_at', '>=', $week->from)->where('occurred_at', '<', $week->to)
            ->groupBy('issue_id')->selectRaw('issue_id, COUNT(*) AS c')->orderByDesc('c')->orderBy('issue_id')->limit(self::LISTED)
            ->pluck('c', 'issue_id');
        $issues = Issue::query()->whereKey($counts->keys()->all())->get()->keyBy('id');

        return array_values($counts->map(function (mixed $c, int $id) use ($issues, $project): ?array {
            $issue = $issues->get($id)?->setRelation('project', $project);

            return $issue === null ? null : [
                'reference' => $issue->number !== null ? $issue->reference() : null,
                'title' => $issue->title,
                'occurrences' => (int) $c,
                'url' => route('issues.show', [$project, $issue]),
            ];
        })->filter()->all());
    }

    /**
     * Of the week's busiest routes, those whose p95 grew most since the week before.
     *
     * @return list<array{method: string, route: string, p95_ms: float, previous_p95_ms: float}>
     */
    private function slowerRoutes(Project $project, MetricFilter $filter, MetricWindow $before): array
    {
        $rows = [];

        // ponytail: one query per compared route for the week before; group by route if it shows up as slow.
        foreach ($this->routes->get($project->id, Period::Week, sort: 'count', limit: self::ROUTES_COMPARED) as $route) {
            if ($route['p95'] === null) {
                continue;
            }

            $previous = $this->metrics->value(Metric::P95, new MetricFilter($filter->projectIds, route: $route['route']), $before);
            $current = round($route['p95'] / 1000, 1);

            if ($previous !== null && $current > $previous) {
                $rows[] = ['method' => $route['method'], 'route' => $route['route'], 'p95_ms' => $current, 'previous_p95_ms' => $previous];
            }
        }

        return array_values(collect($rows)->sortByDesc(fn (array $row): float => $row['p95_ms'] - $row['previous_p95_ms'])->take(self::LISTED)->all());
    }
}
