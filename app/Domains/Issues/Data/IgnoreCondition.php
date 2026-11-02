<?php

declare(strict_types=1);

namespace App\Domains\Issues\Data;

use Carbon\CarbonImmutable;

/**
 * When an ignored issue comes back on its own: after a while, after so many
 * more occurrences, or when it escalates. Unless "forever" is chosen, an
 * ignored issue comes back when it escalates, so noise is quiet but a real
 * problem still shows.
 */
final readonly class IgnoreCondition
{
    /** The choices the UI and MCP offer, as one string each. */
    public const array CHOICES = ['escalation', '1h', '24h', '7d', 'count:10', 'count:100', 'count:1000', 'forever'];

    public function __construct(
        public ?CarbonImmutable $until = null,
        public ?int $moreOccurrences = null,
        public bool $escalation = false,
    ) {
    }

    /**
     * "24h", "count:100" or "forever"; nothing or anything else is until it escalates.
     */
    public static function fromChoice(?string $choice): self
    {
        return match (true) {
            $choice === 'forever' => new self(),
            $choice === '1h' => new self(until: CarbonImmutable::now()->addHour()),
            $choice === '24h' => new self(until: CarbonImmutable::now()->addDay()),
            $choice === '7d' => new self(until: CarbonImmutable::now()->addWeek()),
            is_string($choice) && preg_match('/^count:(\d+)$/', $choice, $m) === 1 => new self(moreOccurrences: max(1, (int) $m[1])),
            default => self::untilEscalation(),
        };
    }

    public static function untilEscalation(): self
    {
        return new self(escalation: true);
    }

    public function isForever(): bool
    {
        return $this->until === null && $this->moreOccurrences === null && ! $this->escalation;
    }
}
