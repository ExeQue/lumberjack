<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Casts;

use App\Domains\Mcp\Data\McpPermissions;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @implements CastsAttributes<McpPermissions, McpPermissions|array<string, mixed>|null>
 */
class McpPermissionsCast implements CastsAttributes
{
    #[Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): McpPermissions
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return McpPermissions::fromArray(is_array($decoded) ? $decoded : null);
    }

    #[Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $permissions = is_array($value) ? McpPermissions::fromArray($value) : $value;

        return $permissions === null || $permissions->isEmpty() ? null : json_encode($permissions->toArray(), JSON_THROW_ON_ERROR);
    }
}
