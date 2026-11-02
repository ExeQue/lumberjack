<?php

declare(strict_types=1);

use App\Domains\Shared\Support\Scrubber;

covers(Scrubber::class);

test('secrets in headers, session and Laravel Context are filtered', function () {
    $context = (new Scrubber())->scrub([
        'request' => ['headers' => ['Authorization' => 'Bearer x', 'Cookie' => 'laravel_session=abc', 'X-XSRF-TOKEN' => 'abc', 'Accept' => 'text/html']],
        'session' => ['_token' => 'csrf', 'cart_id' => 42, 'password_confirmed_at' => 1730000000],
        'laravel' => ['tenant' => 'acme', 'api_key' => 'sk_live_123'],
    ]);

    expect($context['request']['headers'])->toBe(['Authorization' => '[filtered]', 'Cookie' => '[filtered]', 'X-XSRF-TOKEN' => '[filtered]', 'Accept' => 'text/html'])
        ->and($context['session'])->toBe(['_token' => '[filtered]', 'cart_id' => 42, 'password_confirmed_at' => '[filtered]'])
        ->and($context['laravel'])->toBe(['tenant' => 'acme', 'api_key' => '[filtered]']);
});

test('extra secret fields support words, wildcards and dotted paths', function (string $pattern, array $masked, array $kept) {
    $data = [
        'customer_cpr' => 'x', 'card_type' => 'x', 'discard_reason' => 'x',
        'session' => ['checkout' => ['iban' => 'x', 'step' => 'x'], 'locale' => 'da'],
        'request' => ['headers' => ['x-api-key' => 'x', 'x-request-id' => 'x']],
    ];

    $flat = Illuminate\Support\Arr::dot((new Scrubber())->scrub($data, [$pattern]));

    foreach ($masked as $path) {
        expect($flat[$path] ?? null)->toBe('[filtered]', "{$pattern} should mask {$path}");
    }

    foreach ($kept as $path) {
        expect($flat[$path] ?? null)->toBe('x', "{$pattern} should keep {$path}");
    }
})->with([
    'a plain word matches inside a key' => ['cpr', ['customer_cpr'], ['card_type']],
    'a wildcard matches the whole key name' => ['card_*', ['card_type'], ['discard_reason']],
    'a dotted pattern matches the full path' => ['session.checkout.*', ['session.checkout.iban', 'session.checkout.step'], ['customer_cpr']],
    'a wildcard inside a header path' => ['request.headers.x-*-key', ['request.headers.x-api-key'], ['request.headers.x-request-id']],
]);
