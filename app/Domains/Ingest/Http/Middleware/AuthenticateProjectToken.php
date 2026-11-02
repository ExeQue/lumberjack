<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Middleware;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectKeys;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the project from the secret server token in the Authorization header.
 */
class AuthenticateProjectToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $project = $token !== null && $token !== ''
            ? Project::query()->where('token_hash', ProjectKeys::hash($token))->first()
            : null;

        if ($project === null) {
            return response()->json(['message' => 'Unknown project token.'], 401);
        }

        $request->attributes->set('project', $project);

        return $next($request);
    }
}
