<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use Override;

/**
 * A maintenance window still open past its expected end, or one that ended after
 * it was alerted on as overrun: then it says how long it ran over. Which of the two is
 * decided when it is queued, so a window ended before the overrun is sent still sends both.
 */
final readonly class MaintenanceOverrunAlert implements Alert
{
    public function __construct(
        public MaintenanceWindow $window,
        public string $url,
        public bool $ended,
    ) {
    }

    public function ended(): bool
    {
        return $this->ended;
    }

    /**
     * Minutes past the expected end, up to the end or now.
     */
    public function overrunMinutes(): int
    {
        return max(1, (int) round($this->window->expected_end_at->diffInMinutes($this->window->ended_at ?? now())));
    }

    #[Override]
    public function url(): string
    {
        return $this->url;
    }

    #[Override]
    public function headline(): string
    {
        return (string) __($this->ended() ? 'alerts.messages.maintenance_ended' : 'alerts.messages.maintenance_overrun', [
            'project' => $this->window->project->name,
            'environment' => $this->environment(),
        ]);
    }

    #[Override]
    public function summary(): array
    {
        return [
            'title' => $this->headline(),
            'subject' => $this->window->message ?? (string) __('alerts.maintenance.no_message'),
            'body' => [trans_choice($this->ended() ? 'alerts.maintenance.ran_over' : 'alerts.maintenance.overrun', $this->overrunMinutes(), [
                'expected' => $this->window->expected_end_at->format(__('mail.clock')),
            ])],
        ];
    }

    #[Override]
    public function facts(): array
    {
        return [
            (string) __('alerts.maintenance.facts.environment') => $this->environment(),
            (string) __('alerts.maintenance.facts.started') => $this->window->starts_at->format(__('mail.time')),
            (string) __('alerts.maintenance.facts.expected_end') => $this->window->expected_end_at->format(__('mail.time')),
            (string) __('alerts.maintenance.facts.over_by') => trans_choice('alerts.queue_backlog.minutes', $this->overrunMinutes()),
        ];
    }

    #[Override]
    public function urgency(): string
    {
        return $this->ended() ? 'low' : 'high';
    }

    #[Override]
    public function maintenance(): MaintenanceWindow
    {
        return $this->window;
    }

    private function environment(): string
    {
        return $this->window->environment !== '' ? $this->window->environment : (string) __('alerts.maintenance.every_environment');
    }
}
