<?php

declare(strict_types=1);

namespace App\Domains\Retention\Enums;

/**
 * One kind of stored data, each with its own retention.
 */
enum RetentionCategory: string
{
    case Occurrences = 'occurrences';
    case Requests = 'requests';
    case Queries = 'queries';
    case Spans = 'spans';
    case WebVitals = 'web_vitals';
    case JobRuns = 'job_runs';
    case ScheduledTaskRuns = 'scheduled_task_runs';
    case CommandRuns = 'command_runs';
    case Logs = 'logs';
    case HealthResults = 'health_results';
    case HealthOutages = 'health_outages';
    case QueueSamples = 'queue_samples';
    case HostSamples = 'host_samples';
    case AlertSends = 'alert_sends';
    case AuditLog = 'audit_log';

    public function label(): string
    {
        return __('retention.categories.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('retention.categories.'.$this->value.'.description');
    }

    public function group(): string
    {
        return match ($this) {
            self::Occurrences => 'errors',
            self::Requests, self::Queries, self::Spans, self::WebVitals => 'performance',
            self::JobRuns, self::ScheduledTaskRuns, self::CommandRuns => 'jobs',
            self::Logs => 'logs',
            self::HealthResults, self::HealthOutages => 'health',
            self::QueueSamples, self::HostSamples => 'infrastructure',
            self::AlertSends => 'alerts',
            self::AuditLog => 'administration',
        };
    }

    /**
     * Days kept when no level says otherwise.
     */
    public function defaultDays(): int
    {
        return match ($this) {
            self::Occurrences, self::AlertSends => 90,
            self::Requests, self::Queries, self::Spans, self::Logs, self::HealthResults => 14,
            self::WebVitals, self::JobRuns, self::ScheduledTaskRuns, self::CommandRuns, self::QueueSamples => 30,
            self::HostSamples => 7,
            self::HealthOutages, self::AuditLog => 365,
        };
    }

    /**
     * Data of the whole install, not of a team: only the application level sets it.
     */
    public function applicationOnly(): bool
    {
        return $this === self::AuditLog;
    }

    public function table(): string
    {
        return match ($this) {
            self::Requests => 'request_runs',
            self::Queries => 'query_runs',
            self::Spans => 'span_runs',
            self::Logs => 'log_entries',
            self::HealthResults => 'health_check_results',
            self::AuditLog => 'admin_audit_entries',
            default => $this->value,
        };
    }

    /**
     * The timestamp a row ages by.
     */
    public function timeColumn(): string
    {
        return match ($this) {
            self::JobRuns, self::ScheduledTaskRuns, self::CommandRuns, self::AuditLog => 'created_at',
            self::HealthResults => 'checked_at',
            self::HealthOutages => 'ended_at',
            self::QueueSamples, self::HostSamples => 'sampled_at',
            self::AlertSends => 'sent_at',
            default => 'occurred_at',
        };
    }

    /**
     * @return list<self>
     */
    public static function forTeams(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c): bool => ! $c->applicationOnly()));
    }
}
