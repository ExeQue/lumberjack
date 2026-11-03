<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

/**
 * When a queue backlog rule fires: more than this many jobs waiting and/or the oldest
 * waiting longer than this, for this long. A null threshold is not checked.
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
    ) {
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

        return __('alerts.queue_backlog.threshold', ['what' => implode(' '.__('alerts.queue_backlog.or').' ', $parts), 'minutes' => $this->sustainMinutes]);
    }
}
