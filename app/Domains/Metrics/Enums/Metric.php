<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Enums;

/**
 * Every time series the metrics API serves. Durations are in milliseconds, shares
 * (error rate, host use) are fractions from 0 to 1.
 */
enum Metric: string
{
    case Requests = 'requests';
    case Errors = 'errors';
    case ErrorRate = 'error_rate';
    case P50 = 'p50';
    case P95 = 'p95';
    case P99 = 'p99';
    case Apdex = 'apdex';
    case Occurrences = 'occurrences';
    case NewIssues = 'new_issues';
    case Users = 'users';
    case JobRuns = 'job_runs';
    case JobFailures = 'job_failures';
    case QueueSize = 'queue_size';
    case QueueWait = 'queue_wait';
    case CpuLoad = 'cpu_load';
    case MemoryUsed = 'memory_used';
    case DiskUsed = 'disk_used';
    case LcpP75 = 'lcp_p75';
    case InpP75 = 'inp_p75';
    case ClsP75 = 'cls_p75';

    public function unit(): string
    {
        return match ($this) {
            self::P50, self::P95, self::P99, self::QueueWait, self::LcpP75, self::InpP75 => 'ms',
            self::ErrorRate, self::CpuLoad, self::MemoryUsed, self::DiskUsed => 'ratio',
            self::Apdex, self::ClsP75 => 'score',
            default => 'count',
        };
    }

    /**
     * A sum of events per bucket: empty buckets are zero, not unknown.
     */
    public function isCount(): bool
    {
        return $this->unit() === 'count' && $this !== self::QueueSize;
    }

    /**
     * What one series per value can be split by.
     *
     * @return list<MetricGroup>
     */
    public function groups(): array
    {
        return match ($this) {
            self::Requests, self::Errors, self::ErrorRate, self::Apdex, self::LcpP75, self::InpP75, self::ClsP75 => [MetricGroup::Environment, MetricGroup::Route, MetricGroup::Release],
            self::P50, self::P95, self::P99 => [MetricGroup::Environment, MetricGroup::Route, MetricGroup::Host, MetricGroup::Release],
            self::Occurrences, self::Users => [MetricGroup::Environment, MetricGroup::Host, MetricGroup::Release],
            self::NewIssues => [MetricGroup::Environment, MetricGroup::Release],
            self::JobRuns, self::JobFailures, self::QueueWait => [MetricGroup::Environment, MetricGroup::Queue, MetricGroup::Host],
            self::QueueSize => [MetricGroup::Queue, MetricGroup::Host],
            self::CpuLoad, self::MemoryUsed, self::DiskUsed => [MetricGroup::Host],
        };
    }
}
