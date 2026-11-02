<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Http\Middleware;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Support\McpContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    public function __construct(
        private readonly McpContext $context,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain !== null && $plain !== '' ? McpToken::query()->where('token_hash', McpToken::hash($plain))->first() : null;

        // A suspended user's tokens stop working with them.
        if ($token === null || $token->isExpired() || ($token->user?->isSuspended() ?? false)) {
            return response()->json(['message' => 'Unknown or expired MCP token.'], 401);
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $this->context->authenticate($token);

        return $next($request);
    }
}
