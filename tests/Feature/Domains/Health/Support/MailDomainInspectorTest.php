<?php

declare(strict_types=1);

use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Support\MailDomainInspector;
use Tests\Support\Health\FakesHealthProbes;

covers(MailDomainInspector::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    // A domain with everything in order; each test breaks one thing.
    $this->zone = [
        'MX example.com' => ['mail.example.com'],
        'A mail.example.com' => ['192.0.2.10'],
        'PTR 10.2.0.192.in-addr.arpa' => ['mail.example.com'],
        'TXT example.com' => ['google-site-verification=abc', 'v=spf1 mx -all'],
        'TXT _dmarc.example.com' => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.com'],
        'TXT dkim._domainkey.example.com' => ['v=DKIM1; k=rsa; p=MIIB'],
    ];
    $this->inspect = function (array $changes = [], string $domain = 'example.com', array $selectors = ['dkim'], string $minPolicy = 'quarantine') {
        $this->fakeDnsZone([...$this->zone, ...$changes]);

        return app(MailDomainInspector::class)->inspect($domain, $selectors, $minPolicy);
    };
});

describe('a domain in order', function () {
    it('passes and names the policy', function () {
        $result = ($this->inspect)();

        expect($result->status)->toBe(HealthResultStatus::Ok)
            ->and($result->message)->toBe('MX, SPF, DMARC (reject), DKIM and reverse DNS are in order.')
            ->and($result->metrics)->toBe(['failed' => 0, 'warnings' => 0]);
    });

    it('leaves DKIM out without selectors', function () {
        expect(($this->inspect)(selectors: [])->message)->toBe('MX, SPF, DMARC (reject) and reverse DNS are in order.');
    });

    it('confirms reverse DNS over IPv6 however the address is written', function () {
        $result = ($this->inspect)([
            'A mail.example.com' => [],
            'AAAA mail.example.com' => ['2001:db8::1'],
            'PTR 1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa' => ['mail.example.com.'],
            'AAAA mail.example.com.' => ['2001:0db8:0:0:0:0:0:1'],
        ]);

        expect($result->status)->toBe(HealthResultStatus::Ok);
    });
});

describe('what receivers object to', function () {
    it('reports it', function (array $changes, HealthResultStatus $status, ?string $message) {
        $result = ($this->inspect)($changes);

        // Without a message of ours, the SPF library words the finding.
        expect($result->message)->toBe($message ?? $result->message)
            ->and($result->status)->toBe($status);
    })->with([
        'no MX' => [['MX example.com' => []], HealthResultStatus::Warning, 'No MX records.'],
        'a null MX' => [['MX example.com' => ['']], HealthResultStatus::Failed, 'The domain refuses mail (null MX).'],
        'no reverse DNS' => [['PTR 10.2.0.192.in-addr.arpa' => []], HealthResultStatus::Failed, '192.0.2.10 (mail.example.com) has no reverse DNS.'],
        'reverse DNS that does not point back' => [['PTR 10.2.0.192.in-addr.arpa' => ['host.isp.example.']], HealthResultStatus::Failed, 'The reverse DNS of 192.0.2.10 (host.isp.example) does not point back to it.'],
        'no SPF' => [['TXT example.com' => ['google-site-verification=abc']], HealthResultStatus::Failed, 'No SPF record.'],
        'two SPF records' => [['TXT example.com' => ['v=spf1 mx -all', 'v=spf1 include:_spf.example.net ~all']], HealthResultStatus::Failed, '2 SPF records; receivers reject more than one.'],
        'SPF that lets anyone send' => [['TXT example.com' => ['v=spf1 mx +all']], HealthResultStatus::Failed, 'SPF lets anyone send (+all).'],
        'SPF with a bare all' => [['TXT example.com' => ['v=spf1 all']], HealthResultStatus::Failed, 'SPF lets anyone send (+all).'],
        'neutral SPF' => [['TXT example.com' => ['v=spf1 mx ?all']], HealthResultStatus::Warning, 'SPF ends in ?all, which protects nothing.'],
        'SPF without an end' => [['TXT example.com' => ['v=spf1 mx']], HealthResultStatus::Warning, 'SPF has neither all nor redirect.'],
        'SPF that does not parse' => [['TXT example.com' => ['v=spf1 ipv4:192.0.2.10 -all']], HealthResultStatus::Failed, null],
        'SPF including a domain without SPF' => [['TXT example.com' => ['v=spf1 include:_spf.gone.example -all']], HealthResultStatus::Failed, 'SPF includes _spf.gone.example, which has no valid SPF record.'],
        'SPF including itself' => [['TXT example.com' => ['v=spf1 include:example.com -all']], HealthResultStatus::Failed, 'The SPF record is not valid: The domain example.com is included/redirected-to recursively'],
        'SPF needing more than 10 lookups through its includes' => [[
            'TXT example.com' => ['v=spf1 include:a.example.net include:b.example.net -all'],
            'TXT a.example.net' => ['v=spf1 a:1.example.net a:2.example.net a:3.example.net a:4.example.net a:5.example.net -all'],
            'TXT b.example.net' => ['v=spf1 a:1.example.net a:2.example.net a:3.example.net a:4.example.net -all'],
        ], HealthResultStatus::Failed, 'SPF needs more than 10 DNS lookups, so receivers reject it.'],
        'SPF with all before the end' => [['TXT example.com' => ['v=spf1 -all mx']], HealthResultStatus::Warning, null],
        'no DMARC' => [['TXT _dmarc.example.com' => []], HealthResultStatus::Failed, 'No DMARC record.'],
        'two DMARC records' => [['TXT _dmarc.example.com' => ['v=DMARC1; p=reject', 'v=DMARC1; p=none']], HealthResultStatus::Failed, '2 DMARC records; receivers ignore them all.'],
        'DMARC without a policy' => [['TXT _dmarc.example.com' => ['v=DMARC1; rua=mailto:dmarc@example.com']], HealthResultStatus::Failed, 'The DMARC record has no valid policy.'],
        'a monitoring-only DMARC policy' => [['TXT _dmarc.example.com' => ['v=DMARC1; p=none']], HealthResultStatus::Warning, 'The DMARC policy is none, weaker than quarantine.'],
        'DMARC for part of the mail' => [['TXT _dmarc.example.com' => ['v=DMARC1; p=reject; pct=25']], HealthResultStatus::Warning, 'The DMARC policy applies to 25% of the mail.'],
        'no DKIM key' => [['TXT dkim._domainkey.example.com' => []], HealthResultStatus::Failed, 'No DKIM key for selector dkim.'],
        'a revoked DKIM key' => [['TXT dkim._domainkey.example.com' => ['v=DKIM1; p=']], HealthResultStatus::Failed, 'The DKIM key for selector dkim is revoked.'],
    ]);

    it('accepts what is in order', function (array $changes) {
        expect(($this->inspect)($changes)->status)->toBe(HealthResultStatus::Ok);
    })->with([
        'SPF that soft fails' => [['TXT example.com' => ['v=spf1 mx ~all']]],
        'SPF that hands over to another record' => [['TXT example.com' => ['v=spf1 redirect=_spf.example.net'], 'TXT _spf.example.net' => ['v=spf1 ip4:192.0.2.0/24 -all']]],
        'a record that only starts like SPF' => [['TXT example.com' => ['v=spf1 mx -all', 'v=spf10 nonsense']]],
        'SPF using exactly 10 lookups' => [[
            'TXT example.com' => ['v=spf1 include:a.example.net include:b.example.net -all'],
            'TXT a.example.net' => ['v=spf1 a:1.example.net a:2.example.net a:3.example.net a:4.example.net -all'],
            'TXT b.example.net' => ['v=spf1 a:1.example.net a:2.example.net a:3.example.net a:4.example.net -all'],
        ]],
        'an included record that could be better' => [['TXT example.com' => ['v=spf1 include:_spf.example.net -all'], 'TXT _spf.example.net' => ['v=spf1 -all ip4:192.0.2.0/24']]],
        'DMARC written loosely' => [['TXT _dmarc.example.com' => ['V=DMARC1;P=Reject ;']]],
    ]);

    it('fails over a warning and lists both', function () {
        $result = ($this->inspect)(['TXT example.com' => [], 'TXT _dmarc.example.com' => ['v=DMARC1; p=none']]);

        expect($result->status)->toBe(HealthResultStatus::Failed)
            ->and($result->message)->toBe('No SPF record. The DMARC policy is none, weaker than quarantine.')
            ->and($result->metrics)->toBe(['failed' => 1, 'warnings' => 1]);
    });

    it('lets the weakest policy be none', function () {
        expect(($this->inspect)(['TXT _dmarc.example.com' => ['v=DMARC1; p=none']], minPolicy: 'none')->status)->toBe(HealthResultStatus::Ok);
    });
});

describe('a subdomain without a DMARC record', function () {
    beforeEach(function () {
        $this->zone = [
            'TXT news.mail.example.com' => ['v=spf1 ip4:192.0.2.10 -all'],
            'MX news.mail.example.com' => ['mail.example.com'],
            'A mail.example.com' => ['192.0.2.10'],
            'PTR 10.2.0.192.in-addr.arpa' => ['mail.example.com'],
        ];
    });

    it('takes the subdomain policy of the nearest parent with one', function (string $record, HealthResultStatus $status) {
        $result = ($this->inspect)(['TXT _dmarc.example.com' => [$record]], 'news.mail.example.com', []);

        expect($result->status)->toBe($status);
    })->with([
        'the parent names a subdomain policy' => ['v=DMARC1; p=reject; sp=none', HealthResultStatus::Warning],
        'the parent has only its own policy' => ['v=DMARC1; p=reject', HealthResultStatus::Ok],
    ]);

    it('never asks a top-level domain', function () {
        $result = ($this->inspect)(['TXT _dmarc.com' => ['v=DMARC1; p=reject']], 'news.mail.example.com', []);

        expect($result->message)->toBe('No DMARC record.');
    });
});
