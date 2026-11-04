<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Support;

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Jobs\Queries\QueueStatisticsQuery;
use App\Domains\Metrics\Queries\ProjectSummaryQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Shared\Enums\Period;
use Illuminate\Database\Eloquent\Collection;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * The current gauges of a credential's projects in the Prometheus text format, for
 * scraping and alerting. Counts and rates cover the last hour. A fresh in-memory
 * registry per scrape: nothing is kept between requests.
 */
class PrometheusExporter
{
    public const string NAMESPACE = 'lumberjack';

    public function __construct(
        private readonly ProjectSummaryQuery $summary,
        private readonly QueueStatisticsQuery $queues,
    ) {
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    public function render(Collection $projects): string
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $gauge = fn (string $name, string $help, array $labels = []) => $registry->registerGauge(self::NAMESPACE, $name, $help, ['project', ...$labels]);

        $requests = $gauge('requests', 'Requests in the last hour.');
        $errorRate = $gauge('error_rate', 'Share of requests in the last hour that ended in a 5xx.');
        $p95 = $gauge('request_duration_p95_seconds', 'p95 response time in the last hour, from sampled requests.');
        $apdex = $gauge('apdex', 'Apdex in the last hour, from 0 to 1.');
        $openIssues = $gauge('open_issues', 'Open issues.');
        $users = $gauge('users_affected', 'Distinct users with an error in the last hour.');
        $failedJobs = $gauge('failed_jobs', 'Jobs that failed in the last hour.');
        $health = $gauge('health_checks', 'Health checks per state.', ['state']);
        $queueSize = $gauge('queue_size', 'Jobs waiting, as the agent reported last.', ['connection', 'queue']);
        $queueWorkers = $gauge('queue_workers', 'Workers running on the queue, as the agent reported last.', ['connection', 'queue']);
        $queueAge = $gauge('queue_oldest_age_seconds', 'Age of the oldest waiting job.', ['connection', 'queue']);
        $agents = $gauge('agents_online', 'Agents that checked in within the last five minutes.');
        $database = [
            'size_bytes' => $gauge('database_size_bytes', 'Database size, as its health check reported last.', ['environment', 'check']),
            'connections' => $gauge('database_connections', 'Open database connections, as its health check reported last.', ['environment', 'check']),
            'max_connections' => $gauge('database_max_connections', 'Connections the database allows, as its health check reported last.', ['environment', 'check']),
        ];
        $databaseChecks = HealthCheck::query()->whereIn('project_id', $projects->modelKeys())->whereNotArchived()->whereReportsDatabase()
            ->with('hosts')->get()->groupBy('project_id');

        $online = ProjectAgent::query()->whereIn('project_id', $projects->modelKeys())
            ->where('last_seen_at', '>', now()->subMinutes(ProjectAgent::OFFLINE_AFTER_MINUTES))
            ->groupBy('project_id')->selectRaw('project_id, COUNT(*) AS c')->pluck('c', 'project_id');

        foreach ($projects as $project) {
            $label = [$project->slug];
            $summary = $this->summary->get($project, Period::Hour, null);

            $requests->set($summary['requests'], $label);
            $errorRate->set($summary['error_rate'], $label);
            $p95->set($summary['p95'] / 1000, $label);
            // No requests, no score: a 0 would read as the worst Apdex there is.
            if ($summary['apdex'] !== null) {
                $apdex->set($summary['apdex'], $label);
            }

            $openIssues->set($summary['open_issues'], $label);
            $users->set($summary['users'], $label);
            $failedJobs->set($summary['failed_jobs'], $label);
            $agents->set((int) ($online[$project->id] ?? 0), $label);

            foreach (['ok', 'degraded', 'down', 'recovering', 'stale', 'pending'] as $state) {
                $health->set((int) ($summary['health'][$state] ?? 0), [...$label, $state]);
            }

            foreach ($databaseChecks->get($project->id, []) as $check) {
                $metrics = $check->hosts->sortByDesc('last_result_at')->first()->last_metrics ?? [];

                foreach ($database as $name => $figure) {
                    if (isset($metrics[$name])) {
                        $figure->set($metrics[$name], [...$label, $check->environment, (string) $check->slug]);
                    }
                }
            }

            foreach ($this->queues->get($project->id, Period::Hour) as $queue) {
                if ($queue['size'] !== null) {
                    $queueSize->set($queue['size'], [...$label, $queue['connection'], $queue['queue']]);
                }

                if ($queue['workers'] !== null) {
                    $queueWorkers->set($queue['workers'], [...$label, $queue['connection'], $queue['queue']]);
                }

                if ($queue['oldest_age_s'] !== null) {
                    $queueAge->set($queue['oldest_age_s'], [...$label, $queue['connection'], $queue['queue']]);
                }
            }
        }

        return (new RenderTextFormat())->render($registry->getMetricFamilySamples());
    }
}
