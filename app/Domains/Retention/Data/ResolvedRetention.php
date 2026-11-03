<?php

declare(strict_types=1);

namespace App\Domains\Retention\Data;

use App\Domains\Retention\Enums\RetentionLevel;

/**
 * The retention in force for one scope; null days means forever.
 */
final readonly class ResolvedRetention
{
    public function __construct(
        public ?int $days,
        public RetentionLevel $source,
        /** The stored value was above the cap and the cap applies instead. */
        public bool $capped = false,
        /** The most the levels above allow; null days with a null level means no cap. */
        public ?int $capDays = null,
        public ?RetentionLevel $capLevel = null,
    ) {
    }

    public function forever(): bool
    {
        return $this->days === null;
    }

    /** @return array{days: int|null, source: string, capped: bool, cap_days: int|null, cap_level: string|null} */
    public function toArray(): array
    {
        return [
            'days' => $this->days,
            'source' => $this->source->value,
            'capped' => $this->capped,
            'cap_days' => $this->capDays,
            'cap_level' => $this->capLevel?->value,
        ];
    }
}
