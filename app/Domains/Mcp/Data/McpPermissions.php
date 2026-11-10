<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Data;

use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;

/**
 * What a token may do, per area: a token's Default, or one project's changes to it.
 */
final readonly class McpPermissions
{
    /**
     * @param  array<string, McpAreaGrant>  $areas  keyed by McpArea value
     */
    public function __construct(public array $areas = [])
    {
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $areas = [];

        foreach ((array) $data as $key => $grant) {
            $area = McpArea::tryFrom((string) $key);

            if ($area !== null && is_array($grant)) {
                $areas[$area->value] = McpAreaGrant::fromArray($grant);
            }
        }

        return new self($areas);
    }

    /**
     * Every area at the given level, capped to what each area offers.
     */
    public static function everywhere(McpLevel $level): self
    {
        $areas = [];

        foreach (McpArea::cases() as $area) {
            $areas[$area->value] = new McpAreaGrant($area->cap($level));
        }

        return new self($areas);
    }

    public function with(McpArea $area, McpAreaGrant $grant): self
    {
        return new self([...$this->areas, $area->value => $grant]);
    }

    public function area(McpArea $area): ?McpAreaGrant
    {
        return $this->areas[$area->value] ?? null;
    }

    public function level(McpArea $area): ?McpLevel
    {
        return $this->area($area)?->level;
    }

    public function exception(McpArea $area, string $tool): ?bool
    {
        return $this->area($area)?->exception($tool);
    }

    public function isEmpty(): bool
    {
        return collect($this->areas)->every(fn (McpAreaGrant $grant): bool => $grant->isEmpty());
    }

    /**
     * @return array<string, array{level?: string, grant?: list<string>, deny?: list<string>}>
     */
    public function toArray(): array
    {
        return array_filter(array_map(fn (McpAreaGrant $grant): array => $grant->toArray(), $this->areas));
    }
}
