<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Support;

use App\Domains\Mcp\Attributes\AlwaysAvailable;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Servers\LumberjackServer;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use LogicException;
use ReflectionClass;

/**
 * Every MCP tool with the permission it needs, read from its attributes.
 *
 * @phpstan-type ToolEntry array{name: string, class: class-string, area: McpArea|null, level: McpLevel|null, destructive: bool, open_world: bool, public: bool}
 */
class McpToolCatalog
{
    /** @var array<string, ToolEntry>|null */
    private static ?array $tools = null;

    /**
     * @return array<string, ToolEntry> keyed by tool name
     */
    public function all(): array
    {
        if (self::$tools === null) {
            self::$tools = [];

            foreach (LumberjackServer::TOOLS as $class) {
                $entry = self::describe($class);
                self::$tools[$entry['name']] = $entry;
            }
        }

        return self::$tools;
    }

    /**
     * @return ToolEntry|null
     */
    public function find(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * The tools of one area.
     *
     * @return array<string, ToolEntry>
     */
    public function inArea(McpArea $area): array
    {
        return array_filter($this->all(), fn (array $entry): bool => $entry['area'] === $area);
    }

    /**
     * @param  class-string  $class
     * @return ToolEntry
     */
    public static function describe(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $permission = ($reflection->getAttributes(RequiresPermission::class)[0] ?? null)?->newInstance();

        if ($permission === null && $reflection->getAttributes(AlwaysAvailable::class) === []) {
            throw new LogicException($class.' declares neither #[RequiresPermission] nor #[AlwaysAvailable].');
        }

        return [
            'name' => (string) $reflection->getDefaultProperties()['name'],
            'class' => $class,
            'area' => $permission?->area,
            'level' => $permission?->level,
            'destructive' => (($reflection->getAttributes(IsDestructive::class)[0] ?? null)?->newInstance()->value ?? false),
            'open_world' => (($reflection->getAttributes(IsOpenWorld::class)[0] ?? null)?->newInstance()->value ?? false),
            'public' => $permission->makesPublic ?? false,
        ];
    }
}
