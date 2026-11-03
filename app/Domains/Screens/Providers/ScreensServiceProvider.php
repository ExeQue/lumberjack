<?php

declare(strict_types=1);

namespace App\Domains\Screens\Providers;

use App\Domains\Screens\Support\TvCredentials;
use App\Domains\Screens\Support\TvViewer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ScreensServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The "tv" guard: the displays and pairings a browser's window cookies prove, as a
        // TvViewer. Only broadcast channels use it, to let a TV without a session listen;
        // everything else asks TvCredentials directly, by window.
        Auth::viaRequest('tv', fn (Request $request): ?TvViewer => TvCredentials::viewer($request));

        // A TV or share link viewer: per share key, or per window of a computer, and per
        // address so made up cookies buy nothing.
        RateLimiter::for('screen-viewer', fn (Request $request): array => [
            Limit::perMinute(120)->by('viewer:'.sha1($request->route()?->originalParameter('key')
                ?? (TvCredentials::deviceHash($request) ?? $request->ip()).'|'.$request->route()?->originalParameter('slot'))),
            Limit::perMinute(600)->by('viewer-ip:'.$request->ip()),
        ]);

        // Looking up and confirming pairing codes: 32^6 codes cannot be guessed at this rate.
        RateLimiter::for('tv-pair', fn (Request $request): array => [
            Limit::perMinute(10)->by('tv-pair-user:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(30)->by('tv-pair-ip:'.$request->ip()),
        ]);
    }
}
