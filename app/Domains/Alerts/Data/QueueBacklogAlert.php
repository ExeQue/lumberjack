<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;

/**
 * A queue has had too many jobs waiting, or one waiting too long, for a while; or it
 * is back under the thresholds. Workers that are all dead start no job, so their wait
 * is never measured; the queue size from the heartbeat still grows.
 */
final readonly class QueueBacklogAlert implements Alert
{
    public function __construct(
        public Project $project,
        public string $connection,
        public string $queue,
        public int $size,
        public ?int $oldestAgeSeconds,
        public QueueBacklogSettings $settings,
        public CarbonImmutable $since,
        public bool $recovered,
        public string $url,
    ) {
    }

    public function url(): string
    {
        return $this->url;
    }

    public function headline(): string
    {
        return __($this->recovered ? 'alerts.messages.queue_backlog_recovered' : 'alerts.messages.queue_backlog', ['project' => $this->project->name]);
    }

    public function subject(): string
    {
        return $this->connection.':'.$this->queue;
    }

    public function summary(): array
    {
        $minutes = max(1, (int) round($this->since->diffInMinutes(now())));

        return [
            'title' => $this->headline(),
            'subject' => $this->subject(),
            'body' => [$this->recovered
                ? trans_choice('alerts.queue_backlog.recovered', $minutes, ['size' => number_format($this->size)])
                : trans_choice('alerts.queue_backlog.started', $minutes)],
        ];
    }

    public function facts(): array
    {
        return [
            (string) __('alerts.queue_backlog.facts.waiting') => number_format($this->size),
            (string) __('alerts.queue_backlog.facts.oldest') => $this->oldestAgeSeconds !== null ? trans_choice('alerts.queue_backlog.minutes', intdiv($this->oldestAgeSeconds, 60)) : '-',
            (string) __('alerts.queue_backlog.facts.threshold') => $this->settings->describe(),
        ];
    }

    public function urgency(): string
    {
        return $this->recovered ? 'low' : 'high';
    }
}
