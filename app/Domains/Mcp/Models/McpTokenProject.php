<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Models;

use App\Domains\Mcp\Casts\McpPermissionsCast;
use App\Domains\Mcp\Data\McpPermissions;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A project a token covers, with the token's changes to its Default for that project.
 *
 * @property int $mcp_token_id
 * @property int $project_id
 * @property McpPermissions $permissions
 */
class McpTokenProject extends Pivot
{
    protected $table = 'mcp_token_project';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['permissions' => McpPermissionsCast::class];
    }
}
