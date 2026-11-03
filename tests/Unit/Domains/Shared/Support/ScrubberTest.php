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

test('values that look like secrets are masked whatever their key', function (string $value, string $expected) {
    expect((new Scrubber())->scrub(['note' => $value])['note'])->toBe($expected);
})->with([
    'bearer token' => ['Bearer abcdefgh12345678', 'Bearer [filtered]'],
    'token in a query string' => ['https://app.test/reset?email=a%40b.c&token=s3cr3t&page=2', 'https://app.test/reset?email=a%40b.c&token=[filtered]&page=2'],
    'signed URL' => ['https://s3.test/f?X-Amz-Expires=300&X-Amz-Signature=deadbeef', 'https://s3.test/f?X-Amz-Expires=300&X-Amz-Signature=[filtered]'],
    'password in a DSN' => ['pgsql://app:hunter2@db/app', 'pgsql://app:[filtered]@db/app'],
    'JWT' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl', '[filtered]'],
    'AWS key id' => ['AKIAIOSFODNN7EXAMPLE', '[filtered]'],
    'Stripe secret key' => ['sk_live_abcdefghijklmnop', '[filtered]'],
    'status screen share link' => ['https://lumberjack.test/s/ls_abcdefghij0123456789abcdefghij0123456789/data', 'https://lumberjack.test/s/[filtered]/data'],
    'harmless text' => ['keyword=lumber&monkey=1', 'keyword=lumber&monkey=1'],
]);

test('patterns of your own mask values too', function () {
    expect((new Scrubber(['/\bACME-\d{6}\b/' => '[acme]']))->scrubString('order ACME-123456'))->toBe('order [acme]');
});

test('a context larger than the limit keeps what fits and marks the rest', function () {
    $context = (new Scrubber())->scrub(['small' => 'kept', 'huge' => str_repeat('x', Scrubber::MAX_BYTES), 'after' => 'kept too']);

    expect($context['small'])->toBe('kept')
        ->and($context['huge'])->toStartWith(Scrubber::TRUNCATED)
        ->and($context['after'])->toBe('kept too')
        ->and(strlen((string) json_encode($context)))->toBeLessThan(Scrubber::MAX_BYTES);
});
