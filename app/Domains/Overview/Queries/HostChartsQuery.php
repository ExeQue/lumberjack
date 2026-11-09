<?php

declare(strict_types=1);

namespace App\Domains\Overview\Queries;

use App\Domains\Metrics\Data\MetricFilter;
use App\Domains\Metrics\Data\MetricWindow;
use App\Domains\Metrics\Enums\Metric;
use App\Domains\Metrics\Enums\MetricGroup;
use App\Domains\Metrics\Queries\MetricSeriesQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\StatisticsCache;

/**
 * The overview's figures per host, on the same buckets so they can be drawn side by
 * side: CPU load per core, the share of memory and disk in use, the p95 response time
 * in milliseconds, and the exceptions and failed jobs per bucket.
 */
class HostChartsQuery
{
    /** ponytail: a fleet above this loses its quietest hosts from the charts and the focus list. */
    private const int HOSTS = 50;

    public function __construct(
        private readonly MetricSeriesQuery $metrics,
    ) {
    }

    /**
     * @return array<'cpu_load'|'memory_used'|'disk_used'|'p95'|'occurrences'|'job_failures', list<array{name: string, points: list<array{t: string, v: float|null}>}>>
     */
    public function get(Project $project, Period $period, ?string $environment = null): array
    {
        $filter = new MetricFilter([$project->id], environment: $environment, groupBy: MetricGroup::Host);
        $window = MetricWindow::forPeriod($period);

        return [
            'cpu_load' => $this->chart(Metric::CpuLoad, $filter, $window),
            'memory_used' => $this->chart(Metric::MemoryUsed, $filter, $window),
            'disk_used' => $this->chart(Metric::DiskUsed, $filter, $window),
            // The heavy ones read the runs, so they share the overview's cache.
            ...StatisticsCache::remember('overview-hosts', $period, [$project->id, $environment], fn (): array => [
                'p95' => $this->chart(Metric::P95, $filter, $window),
                'occurrences' => $this->chart(Metric::Occurrences, $filter, $window),
                'job_failures' => $this->chart(Metric::JobFailures, $filter, $window),
            ]),
        ];
    }

    /**
     * One series per host, by name, with null in the buckets the host did not report.
     *
     * @return list<array{name: string, points: list<array{t: string, v: float|null}>}>
     */
    private function chart(Metric $metric, MetricFilter $filter, MetricWindow $window): array
    {
        $hosts = [];

        foreach ($this->metrics->series($metric, $filter, $window, self::HOSTS) as $point) {
            if ($point['series'] !== MetricSeriesQuery::NONE) {
                $hosts[$point['series']][$point['t']] = $point['value'];
            }
        }

        ksort($hosts);
        $buckets = array_map(fn (int $bucket): string => gmdate('Y-m-d\TH:i:s\Z', $bucket), $window->buckets());
        $series = [];

        foreach ($hosts as $name => $values) {
            $series[] = [
                'name' => (string) $name,
                'points' => array_map(fn (string $t): array => ['t' => $t, 'v' => $values[$t] ?? null], $buckets),
            ];
        }

        return $series;
    }
}
