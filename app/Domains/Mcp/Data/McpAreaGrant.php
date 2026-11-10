<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Data;

use App\Domains\Mcp\Enums\McpLevel;

/**
 * A token's level in one area, plus single tools added beyond it or taken away from it.
 * On a project override the level may be null: the project then follows Default's level.
 */
final readonly class McpAreaGrant
{
    /**
     * @param  list<string>  $grant  tool names added beyond the level
     * @param  list<string>  $deny  tool names taken away from the level
     */
    public function __construct(
        public ?McpLevel $level,
        public array $grant = [],
        public array $deny = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $names = fn (mixed $list): array => array_values(array_unique(array_filter((array) $list, is_string(...))));

        return new self(
            McpLevel::tryFrom((string) ($data['level'] ?? '')),
            $names($data['grant'] ?? []),
            $names($data['deny'] ?? []),
        );
    }

    /**
     * True when the tool is added, false when it is taken away, null when the level decides.
     */
    public function exception(string $tool): ?bool
    {
        return match (true) {
            in_array($tool, $this->deny, true) => false,
            in_array($tool, $this->grant, true) => true,
            default => null,
        };
    }

    public function isEmpty(): bool
    {
        return $this->level === null && $this->grant === [] && $this->deny === [];
    }

    /**
     * @return array{level?: string, grant?: list<string>, deny?: list<string>}
     */
    public function toArray(): array
    {
        return array_filter([
            'level' => $this->level?->value,
            'grant' => $this->grant,
            'deny' => $this->deny,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
