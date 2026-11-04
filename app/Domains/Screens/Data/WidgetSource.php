<?php

declare(strict_types=1);

namespace App\Domains\Screens\Data;

use App\Domains\Metrics\Enums\Metric;
use App\Domains\Screens\Enums\WidgetType;

/**
 * One thing a widget can show: its unit, default amber and red limits (a value at or
 * past them is amber or red; at or below when low is bad), the metric of the metrics
 * API behind it, and which of the scope's filters (route, queue, job, host, check) it takes.
 */
final readonly class WidgetSource
{
    /**
     * @param  'route'|'queue'|'job'|'host'|'check'|null  $filterOn  check is a health check's slug
     */
    public function __construct(
        public WidgetType $type,
        public string $id,
        public string $unit,
        public ?float $amber = null,
        public ?float $red = null,
        public bool $lowIsBad = false,
        public ?Metric $metric = null,
        public ?string $filterOn = null,
        /** The value is the latest report, not a figure over the period. */
        public bool $snapshot = false,
    ) {
    }

    /**
     * The translation key of its name in the designer and the widget's title.
     */
    public function label(): string
    {
        return 'screens.sources.'.$this->type->value.'.'.$this->id;
    }

    /**
     * What it is about, to group the designer's list: traffic, issues, jobs, infrastructure,
     * databases or health.
     */
    public function topic(): string
    {
        return match (true) {
            in_array($this->id, ['open_issues', 'users_affected', 'latest_issues', 'alert_feed'], true) => 'issues',
            str_contains($this->id, 'job') || str_starts_with($this->id, 'queue') || $this->id === 'scheduled_tasks' => 'jobs',
            str_starts_with($this->id, 'host') || str_starts_with($this->id, 'agents') || $this->id === 'current_release' => 'infrastructure',
            str_starts_with($this->id, 'db_') => 'databases',
            $this->type === WidgetType::Health || str_starts_with($this->id, 'health') => 'health',
            default => 'traffic',
        };
    }

    /**
     * @return array{type: string, id: string, label: string, topic: string, unit: string, amber: float|null, red: float|null, low_is_bad: bool, metric: string|null, filter_on: string|null, snapshot: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'label' => __($this->label()),
            'topic' => $this->topic(),
            'unit' => $this->unit,
            'amber' => $this->amber,
            'red' => $this->red,
            'low_is_bad' => $this->lowIsBad,
            'metric' => $this->metric?->value,
            'filter_on' => $this->filterOn,
            'snapshot' => $this->snapshot,
        ];
    }
}
