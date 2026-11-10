<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Actions;

use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateMcpTokenAction
{
    public function __construct(
        private readonly UpdateMcpTokenAction $updateMcpTokenAction,
    ) {
    }

    /**
     * @param  list<int>  $projectIds  projects of the same team; ignored when it covers them all
     * @param  array<int, McpPermissions>  $changes  per project id, changes to Default
     * @return array{token: McpToken, plain: string}
     */
    public function execute(Team $team, User $creator, string $name, bool $allProjects, array $projectIds, McpPermissions $permissions, ?CarbonInterface $expiresAt, array $changes = []): array
    {
        $plain = 'lj_mcp_'.Str::random(40);

        $token = DB::transaction(function () use ($team, $creator, $name, $allProjects, $projectIds, $permissions, $changes, $expiresAt, $plain): McpToken {
            $token = $team->mcpTokens()->create([
                'user_id' => $creator->id,
                'name' => $name,
                'token_hash' => McpToken::hash($plain),
                'expires_at' => $expiresAt,
            ]);

            return $this->updateMcpTokenAction->execute($token, $creator, $name, $allProjects, $projectIds, $permissions, $changes);
        });

        return ['token' => $token, 'plain' => $plain];
    }
}
