<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Middleware;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectKeys;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the project from the secret server token in the Authorization header.
 */
class AuthenticateProjectToken
{
    /** Wrong tokens an IP may send per minute before it is turned away unchecked. */
    public const int FAILURES_PER_MINUTE = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $throttle = 'ingest-auth-failed:'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttle, self::FAILURES_PER_MINUTE)) {
            return response()->json(['message' => 'Too many attempts with an unknown token.'], 429);
        }

        $token = $request->bearerToken();
        $project = $token !== null && $token !== ''
            ? Project::query()->where('token_hash', ProjectKeys::hash($token))->first()
            : null;

        if ($project === null) {
            RateLimiter::hit($throttle);

            return response()->json(['message' => 'Unknown project token.'], 401);
        }

        $request->attributes->set('project', $project);

        return $next($request);
    }
}
