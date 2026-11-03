<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Http\Middleware;

use App\Domains\Mcp\Actions\FindUsableMcpTokenAction;
use App\Domains\Metrics\Support\MetricsCredential;
use App\Domains\Projects\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public stats key from the URL, or a team token as a bearer token. Unknown ones
 * count against the address, which is turned away unchecked past the limit.
 */
class AuthenticateMetricsCredential
{
    /** Unknown tokens or keys an IP may send per minute before it is turned away unchecked. */
    public const int FAILURES_PER_MINUTE = 30;

    public function __construct(
        private readonly MetricsCredential $credential,
        private readonly FindUsableMcpTokenAction $findToken,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $throttle = 'metrics-auth-failed:'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttle, self::FAILURES_PER_MINUTE)) {
            return response()->json(['message' => 'Too many attempts with an unknown token or key.'], 429);
        }

        $route = $request->route();
        $key = $route?->parameter('key');

        if (is_string($key)) {
            // The rest of the route is the same as with a token, so the key is not a parameter of it.
            $route->forgetParameter('key');
            $project = Project::query()->where('stats_key', $key)->first();

            if ($project === null) {
                RateLimiter::hit($throttle);

                return response()->json(['message' => 'Unknown public stats key.'], 404);
            }

            $this->credential->authenticatePublic($project);

            return $next($request);
        }

        $token = $this->findToken->execute($request->bearerToken());

        if ($token === null) {
            RateLimiter::hit($throttle);

            return response()->json(['message' => 'Unknown or expired token.'], 401);
        }

        $this->credential->authenticateToken($token);

        return $next($request);
    }
}
