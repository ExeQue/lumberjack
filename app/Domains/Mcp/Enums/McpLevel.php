<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Enums;

/**
 * How much a token may do in an area. Each level includes the ones below it.
 */
enum McpLevel: string
{
    case None = 'none';
    case Read = 'read';
    case Write = 'write';
    case Manage = 'manage';

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Read => 1,
            self::Write => 2,
            self::Manage => 3,
        };
    }

    public function includes(self $level): bool
    {
        return $this->rank() >= $level->rank();
    }

    public function label(): string
    {
        return __('mcp.levels.'.$this->value);
    }
}
