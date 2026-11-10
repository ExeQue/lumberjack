<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Enums;

/**
 * A part of Lumberjack a token gets a level for. Team-wide areas are not tied to the
 * token's projects, so a project cannot override them.
 */
enum McpArea: string
{
    case Issues = 'issues';
    case Telemetry = 'telemetry';
    case Health = 'health';
    case Schedule = 'schedule';
    case Alerts = 'alerts';
    case DataSources = 'data_sources';
    case Screens = 'screens';
    case Project = 'project';
    case Team = 'team';

    public function isTeamWide(): bool
    {
        return in_array($this, [self::Alerts, self::Screens, self::Team], true);
    }

    /**
     * The levels this area offers, None first.
     *
     * @return list<McpLevel>
     */
    public function levels(): array
    {
        return match ($this) {
            self::Telemetry, self::Team => [McpLevel::None, McpLevel::Read],
            self::Schedule => [McpLevel::None, McpLevel::Read, McpLevel::Manage],
            default => McpLevel::cases(),
        };
    }

    /**
     * The highest level this area offers at or below the given one.
     */
    public function cap(McpLevel $level): McpLevel
    {
        return collect($this->levels())->filter(fn (McpLevel $offered): bool => $level->includes($offered))->last() ?? McpLevel::None;
    }

    public function label(): string
    {
        return __('mcp.areas.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('mcp.areas.'.$this->value.'.description');
    }
}
