<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Actions;

use App\Domains\Mcp\Models\McpToken;

/**
 * The token a bearer value stands for, when it still works: not expired and its creator
 * still administers the team. Marks it used, at most once a minute. MCP and the metrics
 * API both authenticate through here.
 */
class FindUsableMcpTokenAction
{
    public function execute(?string $plain): ?McpToken
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $token = McpToken::query()->where('token_hash', McpToken::hash($plain))->first();

        if ($token === null || $token->isExpired() || ! $token->isUsable()) {
            return null;
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $token;
    }
}
