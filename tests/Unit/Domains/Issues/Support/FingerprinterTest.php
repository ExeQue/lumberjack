<?php

declare(strict_types=1);

use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Support\Fingerprinter;
use Carbon\CarbonImmutable;

covers(Fingerprinter::class);

beforeEach(function () {
    $this->fingerprinter = new Fingerprinter();
    $this->signal = fn (IssueSource $source, string $message, array $frames = [], ?string $custom = null, ?string $channel = null): IssueSignal => new IssueSignal(
        $source,
        'TypeError',
        $message,
        null,
        null,
        $frames,
        [],
        CarbonImmutable::now(),
        logChannel: $channel,
        customFingerprint: $custom,
    );
});

test('server errors group by their first frame in app code, not by message', function () {
    $frames = [['file' => 'vendor/x.php', 'line' => 1, 'in_app' => false], ['file' => 'app/A.php', 'line' => 9, 'in_app' => true]];

    expect($this->fingerprinter->fingerprint(($this->signal)(IssueSource::Server, 'one', $frames)))
        ->toBe($this->fingerprinter->fingerprint(($this->signal)(IssueSource::Server, 'two', $frames)));
});

test('browser errors ignore varying numbers and bundle hashes', function () {
    $a = ($this->signal)(IssueSource::Browser, 'Order 12 failed', [['file' => 'https://x.test/app.3f9a12bc.js?v=1', 'line' => 3]]);
    $b = ($this->signal)(IssueSource::Browser, 'Order 99 failed', [['file' => 'https://x.test/app.77de01aa.js?v=2', 'line' => 3]]);

    expect($this->fingerprinter->fingerprint($a))->toBe($this->fingerprinter->fingerprint($b));
});

test('log lines group per channel', function () {
    expect($this->fingerprinter->fingerprint(($this->signal)(IssueSource::Log, 'Failed', channel: 'payments')))
        ->not->toBe($this->fingerprinter->fingerprint(($this->signal)(IssueSource::Log, 'Failed', channel: 'default')));
});

test('a custom fingerprint wins over everything else', function () {
    expect($this->fingerprinter->fingerprint(($this->signal)(IssueSource::Server, 'a', custom: 'same')))
        ->toBe($this->fingerprinter->fingerprint(($this->signal)(IssueSource::Browser, 'b', custom: 'same')));
});

test('messages are normalized', function (string $message, string $expected) {
    expect($this->fingerprinter->normalizeMessage($message))->toBe($expected);
})->with([
    'numbers' => ['Order 88213 failed after 2.5 s', 'Order {n} failed after {n} s'],
    'a uuid' => ['Event 0d9c7a1e-1111-4222-8333-944455556666 missing', 'Event {uuid} missing'],
]);
