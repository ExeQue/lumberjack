<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Http\Middleware;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Support\McpContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    public function __construct(
        private readonly McpContext $context,
    ) {
    }

    /** Unusable tokens an IP may send per minute before it is turned away unchecked. */
    public const int FAILURES_PER_MINUTE = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $throttle = 'mcp-auth-failed:'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttle, self::FAILURES_PER_MINUTE)) {
            return response()->json(['message' => 'Too many attempts with an unknown token.'], 429);
        }

        $plain = $request->bearerToken();
        $token = $plain !== null && $plain !== '' ? McpToken::query()->where('token_hash', McpToken::hash($plain))->first() : null;

        if ($token === null || $token->isExpired() || ! $token->isUsable()) {
            RateLimiter::hit($throttle);

            return response()->json(['message' => 'Unknown or expired MCP token.'], 401);
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $this->context->authenticate($token);

        return $next($request);
    }
}
