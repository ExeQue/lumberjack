<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in, verified user who has not been through the first-login onboarding is sent
 * there on their next page visit, once. Requests for data, the account settings, the
 * sign-in flows, invitations and the TV, kiosk and share pages pass untouched. Runs after
 * RequirePasswordChange, so a password that must be changed comes first.
 */
class RedirectToOnboarding
{
    /** Route names that never redirect. */
    public const array EXCEPT = [
        'onboarding.*', 'logout', 'setup', 'setup.*',
        'profile.*', 'notifications.edit', 'notifications.update', 'push-subscriptions.*', 'appearance.edit',
        'security.edit', 'user-password.update', 'password.*', 'verification.*', 'two-factor.*',
        'invitations.*', 'watching.*', 'tv', 'tv.*', 'pair', 'pair.*', 'screens.show', 'screens.shared', 'screens.shared.*', 'dev.mail.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->onboarded_at !== null || ! $user->hasVerifiedEmail()
            || ! $request->isMethod('GET') || $request->expectsJson()
            // Unnamed routes are framework plumbing (broadcasting auth, devtools).
            || $request->route()?->getName() === null || $request->routeIs(...self::EXCEPT)) {
            return $next($request);
        }

        return redirect()->guest(route('onboarding.show'));
    }
}
