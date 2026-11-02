<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Middleware;

use App\Domains\Administration\Enums\SetupState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /setup answers 404 unless setup is open, before anything else runs (no
 * validation, no password hashing). On a set-up Lumberjack without a setup
 * token that costs one read of the setup flag and no database query, so
 * hammering /setup is as cheap as any 404.
 */
class EnsureSetupIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        // Recovery is only ever open with a token, so with users and no token it is closed for good.
        $closedForGood = RedirectToSetup::flagged() && (string) config('app.setup_token') === '';

        abort_if($closedForGood || ! SetupState::current()->isOpen(), 404);

        return $next($request);
    }
}
