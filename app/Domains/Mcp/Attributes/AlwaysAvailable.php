<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Attributes;

use Attribute;

/**
 * An MCP tool every token may use, such as listing its own projects or permissions.
 * It still sees only what the token covers.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AlwaysAvailable
{
}
