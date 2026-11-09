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
    case DataSourceFetches = 'data_source_fetches';
    case QueueSamples = 'queue_samples';
    case HostSamples = 'host_samples';
    case AlertSends = 'alert_sends';
    case CustomAlerts = 'custom_alerts';
    case AuditLog = 'audit_log';
    case Notifications = 'notifications';

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
            self::HealthResults, self::HealthOutages, self::DataSourceFetches => 'health',
            self::QueueSamples, self::HostSamples => 'infrastructure',
            self::AlertSends, self::CustomAlerts => 'alerts',
            self::AuditLog, self::Notifications => 'administration',
        };
    }

    /**
     * Days kept when no level says otherwise.
     */
    public function defaultDays(): int
    {
        return match ($this) {
            self::Occurrences, self::AlertSends, self::Notifications => 90,
            self::Requests, self::Queries, self::Spans, self::Logs, self::HealthResults => 14,
            // Data source history feeds screen charts, whose longest period is 30 days.
            self::WebVitals, self::JobRuns, self::ScheduledTaskRuns, self::CommandRuns, self::QueueSamples, self::DataSourceFetches, self::CustomAlerts => 30,
            self::HostSamples => 7,
            self::HealthOutages, self::AuditLog => 365,
        };
    }

    /**
     * Data of the whole install or of a person, not of a team: only the application level sets it.
     */
    public function applicationOnly(): bool
    {
        return $this === self::AuditLog || $this === self::Notifications;
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
            self::JobRuns, self::ScheduledTaskRuns, self::CommandRuns, self::AuditLog, self::Notifications => 'created_at',
            self::HealthResults => 'checked_at',
            self::HealthOutages => 'ended_at',
            self::QueueSamples, self::HostSamples => 'sampled_at',
            self::AlertSends => 'sent_at',
            self::DataSourceFetches => 'fetched_at',
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
