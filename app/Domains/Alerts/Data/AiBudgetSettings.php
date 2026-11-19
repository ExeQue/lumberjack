<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

/**
 * When an AI budget rule tells: as a budget passes each of these shares of itself
 * (percent), and, with $projected, when it is on course to go over before its period ends.
 */
final readonly class AiBudgetSettings
{
    /** The shares a rule can tell at. */
    public const array THRESHOLDS = [50, 80, 100];

    public const array DEFAULT_THRESHOLDS = [80, 100];

    /**
     * @param  list<int>  $thresholds  ascending, from THRESHOLDS
     */
    public function __construct(
        public array $thresholds = self::DEFAULT_THRESHOLDS,
        public bool $projected = true,
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

        $thresholds = is_array($settings['thresholds'] ?? null)
            ? array_values(array_intersect(self::THRESHOLDS, array_map(intval(...), array_filter($settings['thresholds'], is_numeric(...)))))
            : self::DEFAULT_THRESHOLDS;

        return new self($thresholds, (bool) ($settings['projected'] ?? true));
    }

    /**
     * @return array{thresholds: list<int>, projected: bool}
     */
    public function toArray(): array
    {
        return ['thresholds' => $this->thresholds, 'projected' => $this->projected];
    }
}
