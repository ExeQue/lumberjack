<?php

declare(strict_types=1);

use App\Domains\Health\Actions\DispatchDueHealthChecksAction;
use App\Domains\Health\Actions\RunHealthCheckAction;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Jobs\RunHealthCheck;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckResult;
use App\Domains\Health\Support\NetworkGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Health\FakesHealthProbes;

covers(RunHealthCheckAction::class, NetworkGuard::class, DispatchDueHealthChecksAction::class);

uses(FakesHealthProbes::class);

beforeEach(function () {
    // The default, whatever the local .env says.
    config(['health.allow_private' => false]);
    $this->fakeDns();
    $this->run = function (HealthCheckType $type, array $config, array $attributes = []): HealthCheckResult {
        $check = HealthCheck::factory()->monitored($type, $config)->create($attributes);
        app(RunHealthCheckAction::class)->execute($check);

        return HealthCheckResult::query()->where('health_check_id', $check->id)->latest('id')->firstOrFail();
    };
});

describe('http checks', function () {
    it('passes on the expected status and measures the latency', function () {
        Http::fake(['https://example.com/up' => Http::response('All good')]);

        $result = ($this->run)(HealthCheckType::Http, ['url' => 'https://example.com/up', 'body_contains' => 'good']);

        expect($result->status)->toBe(HealthResultStatus::Ok)
            ->and($result->metrics)->toHaveKeys(['latency_ms', 'status_code']);
    });

    it('fails on another status or a missing text', function (array $config, string $body, int $status, HealthResultStatus $expected) {
        Http::fake(['*' => Http::response($body, $status)]);

        expect(($this->run)(HealthCheckType::Http, ['url' => 'https://example.com/up', ...$config])->status)->toBe($expected);
    })->with([
        'wrong status' => [[], 'down', 503, HealthResultStatus::Failed],
        'missing text' => [['body_contains' => 'healthy'], 'broken', 200, HealthResultStatus::Failed],
    ]);

    it('warns when it answers slower than allowed', function () {
        Http::fake(function () {
            usleep(20_000);

            return Http::response('ok');
        });

        expect(($this->run)(HealthCheckType::Http, ['url' => 'https://example.com/up', 'max_latency_ms' => 5])->status)->toBe(HealthResultStatus::Warning);
    });

    it('looks for its text only in the first megabyte of the body', function () {
        Http::fake(['*' => Http::response(str_repeat('x', RunHealthCheckAction::MAX_BODY_BYTES).'healthy')]);

        expect(($this->run)(HealthCheckType::Http, ['url' => 'https://example.com/up', 'body_contains' => 'healthy'])->status)->toBe(HealthResultStatus::Failed);
    });

    it('waits no longer than the most a check may, whatever it was saved with', function () {
        $options = null;
        Http::fake(function ($request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response('ok');
        });

        ($this->run)(HealthCheckType::Http, ['url' => 'https://example.com/up', 'timeout_s' => 30]);

        expect($options['timeout'])->toBe(RunHealthCheckAction::MAX_TIMEOUT_S);
    });

    it('pins the vetted address through curl, never streaming, which would drop the pin', function () {
        $options = null;
        Http::fake(function ($request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response('ok');
        });

        ($this->run)(HealthCheckType::Http, ['url' => 'https://example.com/up']);

        expect($options['curl'][CURLOPT_RESOLVE])->toBe(['example.com:443:93.184.215.14'])
            ->and($options)->not->toHaveKey('stream')
            ->and($options['sink'])->toBeResource();
    });

    it('follows a few redirects, and no more', function () {
        Http::fake([
            'https://example.com/old' => Http::response('', 301, ['Location' => '/up']),
            'https://example.com/up' => Http::response('ok'),
            'https://example.com/loop' => Http::response('', 302, ['Location' => '/loop']),
        ]);

        expect(($this->run)(HealthCheckType::Http, ['url' => 'https://example.com/old'])->status)->toBe(HealthResultStatus::Ok)
            ->and(($this->run)(HealthCheckType::Http, ['url' => 'https://example.com/loop'])->message)->toBe('More than 3 redirects.');
    });
});

describe('the private address guard', function () {
    it('refuses hosts on this server or the network behind it', function (string $url, array $addresses) {
        Http::fake();
        $this->fakeDns($addresses);

        $result = ($this->run)(HealthCheckType::Http, ['url' => $url]);

        expect($result->status)->toBe(HealthResultStatus::Failed)
            ->and($result->message)->toContain('private or reserved');
        Http::assertNothingSent();
    })->with([
        'loopback' => ['http://127.0.0.1/', []],
        'metadata service' => ['http://169.254.169.254/latest', []],
        'private network' => ['https://intranet.example.com/', ['10.0.0.5']],
        'one private address among public ones' => ['https://example.com/', ['93.184.215.14', '192.168.1.1']],
        'IPv6 loopback' => ['http://[::1]/', []],
        'IPv4-mapped IPv6' => ['https://example.com/', ['::ffff:127.0.0.1']],
        'IPv6 unique local' => ['https://example.com/', ['fd00::1']],
        'carrier-grade NAT' => ['https://example.com/', ['100.64.1.1']],
        'NAT64 of loopback' => ['https://example.com/', ['64:ff9b::7f00:1']],
        'local-use NAT64' => ['https://example.com/', ['64:ff9b:1::5db8:d70e']],
        '6to4 of a private network' => ['https://example.com/', ['2002:a00:5::1']],
        'Teredo' => ['https://example.com/', ['2001:0:4136:e378::1']],
    ]);

    it('connects to the address it checked, not to a second lookup', function () {
        $options = null;
        Http::fake(function ($request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response('ok');
        });

        ($this->run)(HealthCheckType::Http, ['url' => 'https://example.com:8443/up']);

        expect($options['curl'][CURLOPT_RESOLVE] ?? null)->toBe(['example.com:8443:93.184.215.14']);
    });

    it('checks a redirect target again', function () {
        Http::fake(['https://example.com/up' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);

        expect(($this->run)(HealthCheckType::Http, ['url' => 'https://example.com/up'])->message)->toContain('127.0.0.1 resolves to a private');
        Http::assertSentCount(1);
    });

    it('lets internal checks through when the self-hoster allows them', function () {
        config(['health.allow_private' => true]);
        Http::fake(['*' => Http::response('ok')]);

        expect(($this->run)(HealthCheckType::Http, ['url' => 'http://127.0.0.1/up'])->status)->toBe(HealthResultStatus::Ok);
    });
});

describe('ssl, tcp and dns checks', function () {
    it('warns before a certificate expires and fails close to it', function (int $days, HealthResultStatus $expected) {
        $this->fakeCertificate(now()->addDays($days)->addHour()->toImmutable());

        expect(($this->run)(HealthCheckType::Ssl, ['host' => 'example.com', 'warn_days' => 14, 'fail_days' => 3])->status)->toBe($expected);
    })->with([
        'far off' => [60, HealthResultStatus::Ok],
        'within warning' => [10, HealthResultStatus::Warning],
        'within failure' => [2, HealthResultStatus::Failed],
    ]);

    it('connects to a TCP port', function () {
        $this->fakeTcp();
        expect(($this->run)(HealthCheckType::Tcp, ['host' => 'example.com', 'port' => 5432])->status)->toBe(HealthResultStatus::Ok);

        $this->fakeTcp('Connection refused');
        expect(($this->run)(HealthCheckType::Tcp, ['host' => 'example.com', 'port' => 5432])->message)->toBe('Connection refused');
    });

    it('looks up a DNS record and compares it with the expected value', function () {
        $this->fakeDns(records: ['mail.example.com.']);

        expect(($this->run)(HealthCheckType::Dns, ['name' => 'example.com', 'record_type' => 'MX', 'expected' => 'MAIL.example.com'])->status)->toBe(HealthResultStatus::Ok)
            ->and(($this->run)(HealthCheckType::Dns, ['name' => 'example.com', 'record_type' => 'MX', 'expected' => 'other.example.com'])->status)->toBe(HealthResultStatus::Failed);

        $this->fakeDns(records: []);
        expect(($this->run)(HealthCheckType::Dns, ['name' => 'example.com', 'record_type' => 'A'])->message)->toBe('No A records.');
    });
});

describe('heartbeat checks', function () {
    it('fails once no ping came within the interval and its grace', function () {
        $check = HealthCheck::factory()->monitored(HealthCheckType::Heartbeat, ['grace_minutes' => 5])->create(['interval_minutes' => 15, 'last_ping_at' => now()]);

        $this->travel(19)->minutes();
        expect(app(RunHealthCheckAction::class)->execute($check))->toBeNull();

        $this->travel(2)->minutes();
        expect(app(RunHealthCheckAction::class)->execute($check)?->status)->toBe(HealthResultStatus::Failed);
    });
});

describe('the schedule', function () {
    it('queues each due check once and moves its next run on', function () {
        Queue::fake();
        $due = HealthCheck::factory()->monitored()->create(['interval_minutes' => 5]);
        HealthCheck::factory()->monitored()->create(['next_run_at' => now()->addMinute()]);
        HealthCheck::factory()->monitored()->create(['archived_at' => now()]);
        HealthCheck::factory()->create();

        expect(app(DispatchDueHealthChecksAction::class)->execute())->toBe(1)
            ->and(app(DispatchDueHealthChecksAction::class)->execute())->toBe(0)
            ->and($due->refresh()->next_run_at?->equalTo(now()->startOfMinute()->addMinutes(5)))->toBeTrue();

        Queue::assertPushedOn('health', RunHealthCheck::class);
    });
});
