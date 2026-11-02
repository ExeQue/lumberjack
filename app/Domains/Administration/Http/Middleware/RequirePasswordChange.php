<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user whose password an administrator asked to be changed can do nothing
 * else until they have changed it.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->password_change_required !== true || $request->routeIs('security.edit', 'user-password.update', 'password.confirm', 'password.confirm.store', 'password.confirmation', 'logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('administration.password_change_required'));
        }

        return redirect()->route('security.edit');
    }
}
