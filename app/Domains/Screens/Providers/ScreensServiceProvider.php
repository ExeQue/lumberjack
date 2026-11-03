<?php

declare(strict_types=1);

namespace App\Domains\Screens\Providers;

use App\Domains\Screens\Support\TvCredentials;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ScreensServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The "tv" guard: a paired display by its token cookie, else a TV showing a code
        // by its pairing cookie. Only broadcast channels use it, to let a TV without a
        // session listen; everything else asks TvCredentials directly.
        Auth::viaRequest('tv', fn (Request $request): mixed => TvCredentials::display($request) ?? TvCredentials::pairing($request));

        // A TV or share link viewer: per display, pairing or share key, and per address so
        // made up cookies buy nothing.
        RateLimiter::for('screen-viewer', fn (Request $request): array => [
            Limit::perMinute(120)->by('viewer:'.sha1($request->route()?->originalParameter('key')
                ?? TvCredentials::cookie($request, TvCredentials::DISPLAY_COOKIE)
                ?? TvCredentials::cookie($request, TvCredentials::PAIRING_COOKIE)
                ?? (string) $request->ip())),
            Limit::perMinute(600)->by('viewer-ip:'.$request->ip()),
        ]);

        // Looking up and confirming pairing codes: 32^6 codes cannot be guessed at this rate.
        RateLimiter::for('tv-pair', fn (Request $request): array => [
            Limit::perMinute(10)->by('tv-pair-user:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(30)->by('tv-pair-ip:'.$request->ip()),
        ]);
    }
}
