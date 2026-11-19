<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Enums;

enum AlertRuleType: string
{
    case NewIssue = 'new_issue';
    case Regression = 'regression';
    case Note = 'note';
    case Escalation = 'escalation';
    case StatusChange = 'status_change';
    case NewRelease = 'new_release';
    case CustomAlert = 'custom_alert';
    case QueueBacklog = 'queue_backlog';
    case HealthDegraded = 'health_degraded';
    case HealthDown = 'health_down';
    case HealthRecovered = 'health_recovered';
    case MaintenanceOverrun = 'maintenance_overrun';
    case WeeklySummary = 'weekly_summary';
    case AiBudget = 'ai_budget';
    case AiErrorRate = 'ai_error_rate';

    /**
     * Notes, status changes and the weekly summary go to shared channels only when someone turns that on.
     */
    public function enabledByDefault(): bool
    {
        return $this !== self::Note && $this !== self::StatusChange && $this !== self::WeeklySummary;
    }

    /**
     * The types with one rule per team. Custom alerts have one rule per topic instead.
     *
     * @return list<self>
     */
    public static function standard(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type !== self::CustomAlert));
    }

    /**
     * The group a rule is listed under: issues, releases, health, queues, ai, summary or custom.
     */
    public function group(): string
    {
        return match ($this) {
            self::NewIssue, self::Regression, self::Note, self::Escalation, self::StatusChange => 'issues',
            self::NewRelease => 'releases',
            self::HealthDegraded, self::HealthDown, self::HealthRecovered => 'health',
            self::QueueBacklog, self::MaintenanceOverrun => 'queues',
            self::AiBudget, self::AiErrorRate => 'ai',
            self::WeeklySummary => 'summary',
            self::CustomAlert => 'custom',
        };
    }

    public function label(): string
    {
        return __('alerts.rule_types.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('alerts.rule_types.'.$this->value.'.description');
    }
}
