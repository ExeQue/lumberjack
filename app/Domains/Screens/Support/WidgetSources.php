<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use App\Domains\Metrics\Enums\Metric;
use App\Domains\Screens\Data\WidgetSource;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Exceptions\UnknownWidgetSourceException;

/**
 * Every source a status screen widget can show, per visualization: the one list the
 * designer offers, the server validates against and ResolveWidgetDataAction reads.
 * Durations are in milliseconds, shares from 0 to 1.
 */
final class WidgetSources
{
    /** @var array<string, array<string, WidgetSource>>|null */
    private static ?array $sources = null;

    /**
     * @return array<string, array<string, WidgetSource>>  by type, then id
     */
    public static function all(): array
    {
        return self::$sources ??= self::build();
    }

    /**
     * @return list<WidgetSource>
     */
    public static function for(WidgetType $type): array
    {
        return array_values(self::all()[$type->value] ?? []);
    }

    public static function find(WidgetType $type, string $id): WidgetSource
    {
        return self::all()[$type->value][$id] ?? throw UnknownWidgetSourceException::for($type, $id);
    }

    public static function has(WidgetType $type, string $id): bool
    {
        return isset(self::all()[$type->value][$id]);
    }

    /**
     * @return array<string, array<string, WidgetSource>>
     */
    private static function build(): array
    {
        $stat = WidgetType::Stat;
        $gauge = WidgetType::Gauge;
        $chart = WidgetType::Chart;
        $list = WidgetType::List;

        $sources = [
            // Stat: one number with its trend.
            new WidgetSource($stat, 'requests', 'count', metric: Metric::Requests, filterOn: 'route'),
            new WidgetSource($stat, 'error_rate', 'ratio', 0.01, 0.05, metric: Metric::ErrorRate, filterOn: 'route'),
            new WidgetSource($stat, 'p95', 'ms', 500, 1000, metric: Metric::P95, filterOn: 'route'),
            new WidgetSource($stat, 'apdex', 'score', 0.85, 0.7, lowIsBad: true, metric: Metric::Apdex, filterOn: 'route'),
            new WidgetSource($stat, 'open_issues', 'count', snapshot: true),
            new WidgetSource($stat, 'users_affected', 'count', metric: Metric::Users, filterOn: 'host'),
            new WidgetSource($stat, 'failed_jobs', 'count', 1, 10, metric: Metric::JobFailures, filterOn: 'job'),
            new WidgetSource($stat, 'queue_size', 'count', 100, 1000, metric: Metric::QueueSize, filterOn: 'queue', snapshot: true),
            new WidgetSource($stat, 'agents_online', 'count', red: 0, lowIsBad: true, filterOn: 'host', snapshot: true),
            new WidgetSource($stat, 'host_cpu', 'ratio', 0.7, 0.9, metric: Metric::CpuLoad, filterOn: 'host', snapshot: true),
            new WidgetSource($stat, 'host_memory', 'ratio', 0.8, 0.9, metric: Metric::MemoryUsed, filterOn: 'host', snapshot: true),
            new WidgetSource($stat, 'host_disk', 'ratio', 0.8, 0.9, metric: Metric::DiskUsed, filterOn: 'host', snapshot: true),
            new WidgetSource($stat, 'current_release', 'text', snapshot: true),
            new WidgetSource($stat, 'lcp_p75', 'ms', 2500, 4000, metric: Metric::LcpP75, filterOn: 'route'),

            // Gauge: one value on a scale.
            new WidgetSource($gauge, 'error_rate', 'ratio', 0.01, 0.05, metric: Metric::ErrorRate, filterOn: 'route'),
            new WidgetSource($gauge, 'p95', 'ms', 500, 1000, metric: Metric::P95, filterOn: 'route'),
            new WidgetSource($gauge, 'apdex', 'score', 0.85, 0.7, lowIsBad: true, metric: Metric::Apdex, filterOn: 'route'),
            new WidgetSource($gauge, 'host_cpu', 'ratio', 0.7, 0.9, metric: Metric::CpuLoad, filterOn: 'host', snapshot: true),
            new WidgetSource($gauge, 'host_memory', 'ratio', 0.8, 0.9, metric: Metric::MemoryUsed, filterOn: 'host', snapshot: true),
            new WidgetSource($gauge, 'host_disk', 'ratio', 0.8, 0.9, metric: Metric::DiskUsed, filterOn: 'host', snapshot: true),
            new WidgetSource($gauge, 'queue_size', 'count', 100, 1000, metric: Metric::QueueSize, filterOn: 'queue', snapshot: true),
            new WidgetSource($gauge, 'health_uptime', 'ratio', 0.999, 0.99, lowIsBad: true),

            // Chart: one series (or a split) over the period.
            new WidgetSource($chart, 'requests_errors', 'count', metric: Metric::Requests, filterOn: 'route'),
            new WidgetSource($chart, 'requests', 'count', metric: Metric::Requests, filterOn: 'route'),
            new WidgetSource($chart, 'error_rate', 'ratio', red: 0.05, metric: Metric::ErrorRate, filterOn: 'route'),
            new WidgetSource($chart, 'p95', 'ms', red: 1000, metric: Metric::P95, filterOn: 'route'),
            new WidgetSource($chart, 'apdex', 'score', red: 0.7, lowIsBad: true, metric: Metric::Apdex, filterOn: 'route'),
            new WidgetSource($chart, 'users_affected', 'count', metric: Metric::Users, filterOn: 'host'),
            new WidgetSource($chart, 'queue_size', 'count', red: 1000, metric: Metric::QueueSize, filterOn: 'queue'),
            new WidgetSource($chart, 'queue_wait', 'ms', metric: Metric::QueueWait, filterOn: 'queue'),
            new WidgetSource($chart, 'failed_jobs', 'count', metric: Metric::JobFailures, filterOn: 'job'),
            new WidgetSource($chart, 'host_cpu', 'ratio', red: 0.9, metric: Metric::CpuLoad, filterOn: 'host'),
            new WidgetSource($chart, 'host_memory', 'ratio', red: 0.9, metric: Metric::MemoryUsed, filterOn: 'host'),
            new WidgetSource($chart, 'host_disk', 'ratio', red: 0.9, metric: Metric::DiskUsed, filterOn: 'host'),
            new WidgetSource($chart, 'lcp_p75', 'ms', red: 4000, metric: Metric::LcpP75, filterOn: 'route'),

            // List: rows, worst or newest first.
            new WidgetSource($list, 'latest_issues', 'rows', snapshot: true),
            new WidgetSource($list, 'alert_feed', 'rows'),
            new WidgetSource($list, 'failed_jobs', 'rows', filterOn: 'job'),
            new WidgetSource($list, 'scheduled_tasks', 'rows', filterOn: 'job', snapshot: true),
            new WidgetSource($list, 'slowest_routes', 'rows', 500, 1000, filterOn: 'route'),
            new WidgetSource($list, 'queues', 'rows', 100, 1000, filterOn: 'queue', snapshot: true),
            new WidgetSource($list, 'hosts', 'rows', 0.8, 0.9, filterOn: 'host', snapshot: true),
            new WidgetSource($list, 'agents', 'rows', filterOn: 'host', snapshot: true),

            // Health: every check of the scope, or the picked ones.
            new WidgetSource(WidgetType::Health, 'checks', 'checks'),
        ];

        $byType = [];

        foreach ($sources as $source) {
            $byType[$source->type->value][$source->id] = $source;
        }

        return $byType;
    }
}
