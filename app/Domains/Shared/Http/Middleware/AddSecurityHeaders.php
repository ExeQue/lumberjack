<?php

declare(strict_types=1);

namespace App\Domains\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers every response carries: no framing by other sites (clickjacking), no MIME
 * sniffing, only the origin in the Referer to other sites (so an invitation link's token
 * stays here), no powerful browser features, and HSTS when Lumberjack runs on https.
 * Cross-origin endpoints (browser ingest, the SDK script) are unaffected: none of these
 * restrict who may call them.
 */
class AddSecurityHeaders
{
    public const array HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if (str_starts_with((string) config('app.url'), 'https://')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
