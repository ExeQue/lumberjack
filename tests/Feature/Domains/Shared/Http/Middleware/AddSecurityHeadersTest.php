<?php

declare(strict_types=1);

use App\Domains\Shared\Http\Middleware\AddSecurityHeaders;

covers(AddSecurityHeaders::class);

describe('security headers', function () {
    it('keeps other sites from framing a page or sniffing its type', function () {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy');
    });

    it('sends HSTS only when Lumberjack runs on https', function () {
        config(['app.url' => 'http://lumberjack.test']);
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        config(['app.url' => 'https://lumberjack.test']);
        $this->get('/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    });

    it('leaves browser ingest open to other origins', function () {
        $this->options('/api/ingest/browser?key=nope', [], ['Origin' => 'https://app.example', 'Access-Control-Request-Method' => 'POST'])
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Cross-Origin-Resource-Policy');
    });
});
