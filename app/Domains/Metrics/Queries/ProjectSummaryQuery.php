<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Queries;

use App\Domains\Metrics\Data\MetricFilter;
use App\Domains\Metrics\Data\MetricWindow;
use App\Domains\Metrics\Enums\Metric;
use App\Domains\Overview\Queries\ProjectOverviewQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;

/**
 * A project's headline figures for a period: the overview page's numbers (the same
 * query and cache), plus Apdex and users affected. Durations in milliseconds.
 */
class ProjectSummaryQuery
{
    public function __construct(
        private readonly ProjectOverviewQuery $overview,
        private readonly MetricSeriesQuery $metrics,
    ) {
    }

    /**
     * @return array{project: string, period: string, environment: string|null, requests: int, errors: int, error_rate: float, p50: float, p95: float, p99: float, apdex: float|null, open_issues: int, users: int, job_runs: int, failed_jobs: int, stuck_jobs: int, sample_rate: float|null, health: array<string, int>, previous: array{requests: int, errors: int, p95: float, failed_jobs: int}}
     */
    public function get(Project $project, Period $period, ?string $environment): array
    {
        $overview = $this->overview->get($project, $period, $environment);
        $filter = new MetricFilter([$project->id], $environment);
        $window = MetricWindow::forPeriod($period);
        $health = $overview['health'];
        unset($health['checks']);

        return [
            'project' => $project->slug,
            'period' => $period->value,
            'environment' => $environment,
            'requests' => (int) $overview['requests'],
            'errors' => (int) $overview['errors'],
            'error_rate' => (float) $overview['error_rate'],
            'p50' => round($overview['p50'] / 1000, 1),
            'p95' => round($overview['p95'] / 1000, 1),
            'p99' => round($overview['p99'] / 1000, 1),
            'apdex' => $this->metrics->value(Metric::Apdex, $filter, $window),
            'open_issues' => (int) $overview['open_issues'],
            'users' => (int) $this->metrics->value(Metric::Users, $filter, $window),
            'job_runs' => (int) $overview['job_runs'],
            'failed_jobs' => (int) $overview['failed_jobs'],
            'stuck_jobs' => (int) $overview['stuck_jobs'],
            'sample_rate' => $overview['sample_rate'] !== null ? (float) $overview['sample_rate'] : null,
            'health' => array_map(intval(...), $health),
            'previous' => [
                'requests' => (int) $overview['previous']['requests'],
                'errors' => (int) $overview['previous']['errors'],
                'p95' => round($overview['previous']['p95'] / 1000, 1),
                'failed_jobs' => (int) $overview['previous']['failed_jobs'],
            ],
        ];
    }
}
