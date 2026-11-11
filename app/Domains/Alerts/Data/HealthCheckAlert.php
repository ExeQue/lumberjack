<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Data\Concerns\LabelsMaintenance;
use App\Domains\Health\Models\HealthCheck;
use Carbon\CarbonImmutable;
use Override;

/**
 * A health check became degraded or down, or recovered.
 */
final readonly class HealthCheckAlert implements Alert
{
    use LabelsMaintenance;

    public const string DOWN = 'down';

    public const string DEGRADED = 'degraded';

    public const string RECOVERED = 'recovered';

    /**
     * @param  list<string>  $hosts  the hosts it fails on; empty for a check Lumberjack runs
     */
    public function __construct(
        public HealthCheck $check,
        public string $state,
        public ?string $message,
        public array $hosts,
        public CarbonImmutable $since,
        public string $url,
    ) {
    }

    public function url(): string
    {
        return $this->url;
    }

    public function headline(): string
    {
        return $this->marked((string) __('alerts.messages.health_check_'.$this->state, ['project' => $this->check->project->name]));
    }

    public function summary(): array
    {
        $minutes = max(1, (int) round($this->since->diffInMinutes(now())));

        return [
            'title' => $this->headline(),
            'subject' => $this->check->displayName(),
            'body' => array_values(array_filter([
                $this->message,
                $this->state === self::RECOVERED ? trans_choice('alerts.health_check.recovered', $minutes) : null,
            ])),
        ];
    }

    public function facts(): array
    {
        return [
            (string) __('alerts.health_check.facts.status') => (string) __('alerts.health_check.states.'.$this->state),
            (string) __('alerts.health_check.facts.check') => $this->check->slug ?? trim(__('alerts.health_check.types.'.$this->check->type?->value).' '.($this->check->setting('url') ?? $this->check->setting('host') ?? $this->check->setting('name') ?? '')),
            (string) __('alerts.health_check.facts.environment') => $this->check->environment !== '' ? $this->check->environment : '-',
            (string) __('alerts.health_check.facts.hosts') => $this->hosts !== [] ? implode(', ', $this->hosts) : '-',
        ];
    }

    public function urgency(): string
    {
        return match ($this->state) {
            self::DOWN => 'high',
            self::RECOVERED => 'low',
            default => 'normal',
        };
    }

    #[Override]
    protected function maintenanceScope(): array
    {
        return [$this->check->project_id, $this->check->environment !== '' ? $this->check->environment : null];
    }
}
