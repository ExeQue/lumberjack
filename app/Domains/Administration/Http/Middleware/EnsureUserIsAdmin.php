<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only system administrators past this point.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin() ?? false, 403);

        return $next($request);
    }
}
