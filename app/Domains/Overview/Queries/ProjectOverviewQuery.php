<?php

declare(strict_types=1);

namespace App\Domains\Overview\Queries;

use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Performance\Support\RequestTraffic;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\Percentiles;
use App\Domains\Shared\Support\StatisticsCache;
use App\Domains\Shared\Support\TimeSeries;
use Illuminate\Support\Facades\DB;

class ProjectOverviewQuery
{
    public function __construct(
        private readonly RouteStatisticsQuery $routes,
        private readonly IssuePresenter $presenter,
        private readonly HealthChecksQuery $health,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Project $project, Period $period, ?string $environment = null): array
    {
        return [
            ...StatisticsCache::remember('overview', $period, [$project->id, $environment], fn (): array => $this->compute($project, $period, $environment)),
            // State a person changes (an issue resolved, a check muted) is read fresh: the
            // live reload it causes must show it, not the cached figures from before.
            'health' => $this->health->summary($project, $environment),
            'open_issues' => Issue::query()->where('project_id', $project->id)->whereOpen()->count(),
            'latest_issues' => Issue::query()->where('project_id', $project->id)->whereOpen()->with('assignee')
                ->latest('surfaced_at')->limit(5)->get()->map(fn (Issue $i): array => $this->presenter->summary($i))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(Project $project, Period $period, ?string $environment): array
    {
        $since = $period->since();
        $traffic = RequestTraffic::query($project->id, $since, null, $environment);
        $totals = (clone $traffic)->selectRaw('COALESCE(SUM(requests), 0) AS requests, COALESCE(SUM(requests) FILTER (WHERE status_code >= 500), 0) AS server_errors')->first();
        $requests = (int) round((float) ($totals->requests ?? 0));

        $p50 = Percentiles::select('duration_us', '0.50', 'p50');
        $p95 = Percentiles::select('duration_us', '0.95', 'p95');
        $p99 = Percentiles::select('duration_us', '0.99', 'p99');
        $envSql = $environment !== null ? ' AND environment = ?' : '';
        $bindings = array_filter([$project->id, $since->format('Y-m-d H:i:s'), $environment], fn ($v): bool => $v !== null);

        // Percentiles from the runs the SDK picked: a run kept only for being slow would skew them.
        $latency = DB::selectOne("
            SELECT {$p50}, {$p95}, {$p99}
            FROM request_runs WHERE project_id = ? AND occurred_at >= ?{$envSql} AND sampled", $bindings);

        $errors = DB::table('occurrences')->where('project_id', $project->id)->where('occurred_at', '>=', $since);

        if ($environment !== null) {
            $errors->where('environment', $environment);
        }

        $jobs = JobRun::query()->where('project_id', $project->id)->where('created_at', '>=', $since);

        if ($environment !== null) {
            $jobs->where('environment', $environment);
        }
        $jobRuns = (clone $jobs)->count();
        $failedJobs = (clone $jobs)->whereStatus(JobRunStatus::Failed)->count();
        $stuckJobs = (clone $jobs)->whereStatus(JobRunStatus::Stuck)->count();

        return [
            'previous' => $this->previous($project, $period, $environment),
            'requests' => $requests,
            'errors' => (clone $errors)->count(),
            'error_rate' => (float) ($totals->requests ?? 0) > 0 ? round((float) $totals->server_errors / (float) $totals->requests, 4) : 0.0,
            'p50' => (int) ($latency->p50 ?? 0),
            'p95' => (int) ($latency->p95 ?? 0),
            'p99' => (int) ($latency->p99 ?? 0),
            'job_runs' => $jobRuns,
            'failed_jobs' => $failedJobs,
            'stuck_jobs' => $stuckJobs,
            'request_series' => array_map(fn (array $point): array => [...$point, 'v' => round((float) $point['v'])], TimeSeries::count($traffic, $period, aggregate: 'SUM(requests)')),
            'sample_rate' => $this->routes->sampleRate($project->id, $period, $environment),
            'latency_series' => $this->latencySeries($project, $period, $environment),
            'error_series' => TimeSeries::count($errors, $period),
            'slowest_routes' => array_slice($this->routes->get($project->id, $period, $environment, 'p95', limit: 5), 0, 5),
            'failed_job_runs' => (clone $jobs)->whereStatus(JobRunStatus::Failed, JobRunStatus::Stuck, JobRunStatus::Released)
                ->latest('updated_at')->limit(5)->get()
                ->map(fn (JobRun $j): array => ['name' => $j->name, 'status' => $j->status->value, 'attempt' => $j->attempt, 'max_tries' => $j->max_tries, 'at' => $j->updated_at?->toIso8601String()])->all(),
        ];
    }

    /**
     * The same figures for the period just before, so the cards can show a trend.
     *
     * @return array{requests: int, errors: int, p95: int, failed_jobs: int}
     */
    private function previous(Project $project, Period $period, ?string $environment): array
    {
        $until = $period->since();
        $from = $until->subSeconds((int) now()->diffInSeconds($until, true));
        $range = [$from->format('Y-m-d H:i:s.v'), $until->format('Y-m-d H:i:s.v')];

        $p95 = Percentiles::select('duration_us', '0.95', 'p95');
        $envSql = $environment !== null ? ' AND environment = ?' : '';
        $bindings = array_filter([$project->id, ...$range, $environment], fn ($v): bool => $v !== null);

        $latency = DB::selectOne("
            SELECT {$p95}
            FROM request_runs WHERE project_id = ? AND occurred_at >= ? AND occurred_at < ?{$envSql} AND sampled", $bindings);
        $requests = RequestTraffic::query($project->id, $from, $until, $environment)->sum('requests');

        $errors = DB::table('occurrences')->where('project_id', $project->id)->whereBetween('occurred_at', $range);
        $jobs = JobRun::query()->where('project_id', $project->id)->whereBetween('created_at', $range)->whereStatus(JobRunStatus::Failed);

        if ($environment !== null) {
            $errors->where('environment', $environment);
            $jobs->where('environment', $environment);
        }

        return [
            'requests' => (int) round((float) $requests),
            'errors' => $errors->count(),
            'p95' => (int) ($latency->p95 ?? 0),
            'failed_jobs' => $jobs->count(),
        ];
    }

    /**
     * p50 and p95 response time per bucket; null where no request came in.
     *
     * @return list<array{t: string, p50: int|null, p95: int|null}>
     */
    private function latencySeries(Project $project, Period $period, ?string $environment): array
    {
        $size = $period->bucketSeconds();
        $since = $period->since();
        $start = intdiv($since->getTimestamp(), $size) * $size;
        $envSql = $environment !== null ? ' AND environment = ?' : '';
        $bucket = 'FLOOR(EXTRACT(EPOCH FROM occurred_at) / ?)';
        $bindings = [$size, $project->id, $since->format('Y-m-d H:i:s'), ...($environment !== null ? [$environment] : [])];

        $rows = DB::select("
            SELECT {$bucket} AS bucket, ".Percentiles::select('duration_us', '0.50', 'p50').', '.Percentiles::select('duration_us', '0.95', 'p95')."
            FROM request_runs WHERE project_id = ? AND occurred_at >= ?{$envSql} AND sampled
            GROUP BY 1", $bindings);

        $byBucket = [];

        foreach ($rows as $row) {
            $byBucket[(int) $row->bucket * $size] = ['p50' => (int) $row->p50, 'p95' => (int) $row->p95];
        }

        $series = [];

        for ($i = 0; $i <= $period->buckets(); $i++) {
            $at = $start + $i * $size;

            if ($at > now()->getTimestamp()) {
                break;
            }

            $series[] = ['t' => date(DATE_ATOM, $at), 'p50' => $byBucket[$at]['p50'] ?? null, 'p95' => $byBucket[$at]['p95'] ?? null];
        }

        return $series;
    }
}
