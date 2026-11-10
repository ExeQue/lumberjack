<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Attributes;

use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use Attribute;

/**
 * The area and level an MCP tool needs. A token reaches the tool through that level,
 * or through a single-tool grant, and loses it through a single-tool deny.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresPermission
{
    /**
     * @param  bool  $makesPublic  the tool can expose data outside the team, such as a public link
     */
    public function __construct(
        public McpArea $area,
        public McpLevel $level,
        public bool $makesPublic = false,
    ) {
    }
}
