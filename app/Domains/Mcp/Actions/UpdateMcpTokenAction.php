<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Actions;

use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sets a token's name, the projects it covers and what it may do; its secret stays. The
 * token then acts as the person who set this, so nobody widens a token that acts as someone else.
 */
class UpdateMcpTokenAction
{
    /**
     * @param  list<int>  $projectIds  projects of the token's team; ignored when it covers them all
     * @param  array<int, McpPermissions>  $changes  per project id, changes to Default
     */
    public function execute(McpToken $token, User $by, string $name, bool $allProjects, array $projectIds, McpPermissions $permissions, array $changes = []): McpToken
    {
        return DB::transaction(function () use ($token, $by, $name, $allProjects, $projectIds, $permissions, $changes): McpToken {
            $token->update(['user_id' => $by->id, 'name' => $name, 'all_projects' => $allProjects, 'permissions' => $permissions]);

            $teamProjects = $token->team->projects()->pluck('id')->map(fn (mixed $id): int => (int) $id);
            // Covering every project, a row is only kept for a project with changes.
            $covered = $allProjects ? array_keys($changes) : $projectIds;

            $token->projects()->sync($teamProjects->intersect($covered)->mapWithKeys(fn (int $id): array => [
                $id => ['permissions' => $changes[$id] ?? null],
            ])->all());

            return $token->refresh();
        });
    }
}
