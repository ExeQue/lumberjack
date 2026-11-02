<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Enums;

enum McpScope: string
{
    case Read = 'read';
    case Write = 'write';

    public function label(): string
    {
        return __('mcp.scopes.'.$this->value);
    }
}
