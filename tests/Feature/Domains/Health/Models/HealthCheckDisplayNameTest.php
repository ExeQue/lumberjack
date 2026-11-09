<?php

declare(strict_types=1);

use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Models\HealthCheck;

covers(HealthCheck::class);

describe('the name of a health check', function () {
    it('reads a slug as words', function (string $slug, string $expected) {
        expect((new HealthCheck(['slug' => $slug]))->displayName())->toBe($expected);
    })->with([
        'one part' => ['websockets', 'Websockets'],
        'two parts' => ['queue:default', 'Queue: Default'],
        'three parts' => ['reports:pending:queue', 'Reports: Pending: Queue'],
        'a product name' => ['database:mariadb', 'Database: MariaDB'],
        'a shorthand' => ['mon:perf', 'Monitoring: Performance'],
        'an abbreviation' => ['tcp:reverb', 'TCP: Reverb'],
        'a host keeps its dots' => ['db.internal:5432', 'Db.internal: 5432'],
        'hyphens and underscores' => ['directory-size:event_processing', 'Directory Size: Event Processing'],
    ]);

    it('names a check Lumberjack runs from its settings', function (HealthCheckType $type, array $config, string $expected) {
        expect((new HealthCheck(['type' => $type, 'config' => $config]))->displayName())->toBe($expected);
    })->with([
        'http' => [HealthCheckType::Http, ['url' => 'https://eazy.photo/up/'], 'HTTP: eazy.photo'],
        'ssl' => [HealthCheckType::Ssl, ['host' => 'eazy.photo'], 'SSL: eazy.photo'],
        'ssl on the default port' => [HealthCheckType::Ssl, ['host' => 'eazy.photo', 'port' => 443], 'SSL: eazy.photo'],
        'ssl on another port' => [HealthCheckType::Ssl, ['host' => 'eazy.photo', 'port' => 8443], 'SSL: eazy.photo:8443'],
        'tcp' => [HealthCheckType::Tcp, ['host' => 'db.internal', 'port' => 5432], 'TCP: db.internal:5432'],
        'domain' => [HealthCheckType::Domain, ['domain' => 'eazy.photo'], 'Domain: eazy.photo'],
        'smtp' => [HealthCheckType::Smtp, ['host' => 'mail.eazy.photo', 'port' => 587], 'SMTP: mail.eazy.photo:587'],
        'dns' => [HealthCheckType::Dns, ['name' => 'eazy.photo', 'record_type' => 'MX'], 'DNS: eazy.photo (MX)'],
    ]);

    it('prefers a name given to the check', function () {
        expect((new HealthCheck(['slug' => 'queue:default', 'name' => 'mail queue']))->displayName())->toBe('mail queue');
    });
});
