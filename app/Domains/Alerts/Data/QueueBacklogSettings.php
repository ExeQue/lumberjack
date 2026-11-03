<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\QueueAlertThreshold;
use App\Domains\Projects\Models\Project;

/**
 * When a queue backlog rule fires: more than this many jobs waiting and/or the oldest
 * waiting longer than this, for this long. A null threshold is not checked. $custom is
 * set when a queue's own thresholds replaced some of the rule's; $muted when that queue
 * never alerts.
 */
final readonly class QueueBacklogSettings
{
    public const int DEFAULT_SIZE = 1000;

    public const int DEFAULT_AGE_MINUTES = 10;

    public const int DEFAULT_SUSTAIN_MINUTES = 5;

    public function __construct(
        public ?int $size = self::DEFAULT_SIZE,
        public ?int $ageMinutes = self::DEFAULT_AGE_MINUTES,
        public int $sustainMinutes = self::DEFAULT_SUSTAIN_MINUTES,
        public bool $custom = false,
        public bool $muted = false,
    ) {
    }

    /**
     * The thresholds that count for one queue: the project's own for it where it set one,
     * the rule's otherwise. The one place the check and the alert both read them from.
     */
    public static function forQueue(AlertRule $rule, Project $project, string $connection, string $queue): self
    {
        $settings = $rule->queueBacklogSettings();
        $own = QueueAlertThreshold::query()->where('project_id', $project->id)->where('connection', $connection)->where('queue', $queue)->first();

        return $own === null ? $settings : $settings->overriddenBy($own);
    }

    public function overriddenBy(QueueAlertThreshold $own): self
    {
        return new self(
            $own->size ?? $this->size,
            $own->oldest_minutes ?? $this->ageMinutes,
            $own->sustained_minutes ?? $this->sustainMinutes,
            $own->size !== null || $own->oldest_minutes !== null || $own->sustained_minutes !== null,
            $own->muted,
        );
    }

    /**
     * @param  array<string, mixed>|null  $settings  as stored on the rule
     */
    public static function fromArray(?array $settings): self
    {
        if ($settings === null) {
            return new self();
        }

        $int = fn (string $key): ?int => is_numeric($settings[$key] ?? null) ? (int) $settings[$key] : null;

        return new self(
            array_key_exists('size', $settings) ? $int('size') : self::DEFAULT_SIZE,
            array_key_exists('age_minutes', $settings) ? $int('age_minutes') : self::DEFAULT_AGE_MINUTES,
            $int('sustain_minutes') ?? self::DEFAULT_SUSTAIN_MINUTES,
        );
    }

    /**
     * @return array{size: int|null, age_minutes: int|null, sustain_minutes: int}
     */
    public function toArray(): array
    {
        return ['size' => $this->size, 'age_minutes' => $this->ageMinutes, 'sustain_minutes' => $this->sustainMinutes];
    }

    public function breached(int $size, ?int $oldestAgeSeconds): bool
    {
        return ($this->size !== null && $size > $this->size)
            || ($this->ageMinutes !== null && $oldestAgeSeconds !== null && $oldestAgeSeconds > $this->ageMinutes * 60);
    }

    /**
     * "Over 1,000 jobs or 10 minutes old, for 5 minutes".
     */
    public function describe(): string
    {
        $parts = array_filter([
            $this->size !== null ? trans_choice('alerts.queue_backlog.threshold_size', $this->size, ['size' => number_format($this->size)]) : null,
            $this->ageMinutes !== null ? trans_choice('alerts.queue_backlog.threshold_age', $this->ageMinutes) : null,
        ]);

        return __($this->custom ? 'alerts.queue_backlog.threshold_custom' : 'alerts.queue_backlog.threshold', ['what' => implode(' '.__('alerts.queue_backlog.or').' ', $parts), 'minutes' => $this->sustainMinutes]);
    }
}
