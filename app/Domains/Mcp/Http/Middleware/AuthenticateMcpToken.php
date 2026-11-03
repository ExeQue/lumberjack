<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Http\Middleware;

use App\Domains\Mcp\Actions\FindUsableMcpTokenAction;
use App\Domains\Mcp\Support\McpContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    public function __construct(
        private readonly McpContext $context,
        private readonly FindUsableMcpTokenAction $findToken,
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

        $token = $this->findToken->execute($request->bearerToken());

        if ($token === null) {
            RateLimiter::hit($throttle);

            return response()->json(['message' => 'Unknown or expired MCP token.'], 401);
        }

        $this->context->authenticate($token);

        return $next($request);
    }
}
