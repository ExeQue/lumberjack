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

    /**
     * Notes and status changes go to shared channels only when someone turns that on.
     */
    public function enabledByDefault(): bool
    {
        return $this !== self::Note && $this !== self::StatusChange;
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

    public function label(): string
    {
        return __('alerts.rule_types.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('alerts.rule_types.'.$this->value.'.description');
    }
}
