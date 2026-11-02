<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Actions;

use App\Domains\Mcp\Enums\McpScope;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateMcpTokenAction
{
    /**
     * @param  list<int>  $projectIds  projects of the same team
     * @return array{token: McpToken, plain: string}
     */
    public function execute(Team $team, User $creator, string $name, McpScope $scope, array $projectIds, ?CarbonInterface $expiresAt): array
    {
        $plain = 'lj_mcp_'.Str::random(40);

        $token = DB::transaction(function () use ($team, $creator, $name, $scope, $projectIds, $expiresAt, $plain): McpToken {
            $token = $team->mcpTokens()->create([
                'user_id' => $creator->id,
                'name' => $name,
                'token_hash' => McpToken::hash($plain),
                'scope' => $scope,
                'expires_at' => $expiresAt,
                // A new token's inbox starts now, not at the beginning of time.
            ]);

            $token->projects()->sync($team->projects()->whereIn('id', $projectIds)->pluck('id'));

            return $token;
        });

        return ['token' => $token, 'plain' => $plain];
    }
}
