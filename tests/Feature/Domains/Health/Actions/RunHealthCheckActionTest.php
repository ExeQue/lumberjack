<?php

declare(strict_types=1);

use App\Domains\DataSources\Actions\FetchDataSourceAction;
use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Health\Actions\DispatchDueHealthChecksAction;
use App\Domains\Health\Actions\RunHealthCheckAction;
use App\Domains\Health\Actions\SaveHealthCheckAction;
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

describe('json values in http checks', function () {
    it('compares the value at a path', function (array $config, string $body, HealthResultStatus $expected) {
        Http::fake(['*' => Http::response($body)]);

        expect(($this->run)(HealthCheckType::Http, ['url' => 'https://example.com/up', ...$config])->status)->toBe($expected);
    })->with([
        'a matching value' => [['json_path' => 'status', 'json_expected' => 'ok'], '{"status":"ok"}', HealthResultStatus::Ok],
        'a nested boolean' => [['json_path' => 'checks.db', 'json_expected' => 'true'], '{"checks":{"db":true}}', HealthResultStatus::Ok],
        'another value' => [['json_path' => 'status', 'json_expected' => 'ok'], '{"status":"down"}', HealthResultStatus::Failed],
        'a path that only has to exist' => [['json_path' => 'status'], '{"status":"down"}', HealthResultStatus::Ok],
        'a missing path' => [['json_path' => 'status'], '{"state":"ok"}', HealthResultStatus::Failed],
        'a body that is not json' => [['json_path' => 'status'], 'All good', HealthResultStatus::Failed],
    ]);
});

describe('domain expiry checks', function () {
    beforeEach(function () {
        $this->rdap = fn (string $date) => Http::fake([
            'data.iana.org/*' => Http::response(['services' => [[['photo'], ['https://rdap.nic.photo/']]]]),
            'rdap.nic.photo/domain/eazy.photo' => Http::response(['events' => [['eventAction' => 'registration', 'eventDate' => '2020-01-01T00:00:00Z'], ['eventAction' => 'expiration', 'eventDate' => $date]]]),
        ]);
    });

    it('reads the date from rdap and grades it by the days left', function (int $days, HealthResultStatus $expected) {
        ($this->rdap)(now()->addDays($days)->addHour()->toIso8601String());

        $result = ($this->run)(HealthCheckType::Domain, ['domain' => 'eazy.photo']);

        expect($result->status)->toBe($expected)
            ->and($result->metrics['days_left'])->toBe($days);
    })->with([
        'far off' => [90, HealthResultStatus::Ok],
        'inside the warning' => [20, HealthResultStatus::Warning],
        'inside the failure' => [5, HealthResultStatus::Failed],
    ]);

    it('asks whois for a top-level domain without rdap', function () {
        Http::fake(['data.iana.org/*' => Http::response(['services' => []])]);
        $this->fakeWhois('whois.example', 'Domain: dr.dk'."\n".'Expires:              '.now()->addDays(90)->toDateString()."\n");

        $result = ($this->run)(HealthCheckType::Domain, ['domain' => 'dr.dk']);

        expect($result->status)->toBe(HealthResultStatus::Ok)
            ->and($result->message)->toContain('expires in');
    });

    it('fails and says so when the registry publishes no date', function () {
        Http::fake(['data.iana.org/*' => Http::response(['services' => []])]);
        $this->fakeWhois('whois.example', 'Domain: example.de'."\n".'Status: connect'."\n");

        $result = ($this->run)(HealthCheckType::Domain, ['domain' => 'example.de']);

        expect($result->status)->toBe(HealthResultStatus::Failed)
            ->and($result->message)->toContain('does not publish');
    });

    it('fails for a domain the registry does not know', function () {
        Http::fake([
            'data.iana.org/*' => Http::response(['services' => [[['photo'], ['https://rdap.nic.photo/']]]]),
            'rdap.nic.photo/*' => Http::response([], 404),
        ]);

        expect(($this->run)(HealthCheckType::Domain, ['domain' => 'gone.photo'])->message)->toContain('not registered');
    });
});

describe('smtp checks', function () {
    it('passes when the server answers and fails when it does not', function (?string $error, HealthResultStatus $expected) {
        $this->fakeSmtp($error);

        expect(($this->run)(HealthCheckType::Smtp, ['host' => 'mail.example.com', 'port' => 587])->status)->toBe($expected);
    })->with([
        'it answers' => [null, HealthResultStatus::Ok],
        'it refuses' => ['Connection refused', HealthResultStatus::Failed],
    ]);

    it('is kept off private addresses', function () {
        $this->fakeDns(['10.0.0.5']);
        $this->fakeSmtp();

        expect(($this->run)(HealthCheckType::Smtp, ['host' => 'mail.internal', 'port' => 25])->status)->toBe(HealthResultStatus::Failed);
    });
});

describe('data source checks', function () {
    beforeEach(function () {
        $this->source = DataSource::factory()->create();
        $this->check = fn (array $config = []): HealthCheck => HealthCheck::factory()->for($this->source->project)
            ->monitored(HealthCheckType::DataSource, ['data_source' => $this->source->uuid, 'path' => 'queue.size', 'operator' => '>', 'warn' => 500, 'fail' => 1000, ...$config])->create();
    });

    it('holds the value of each fetch against its limits', function (int $size, HealthResultStatus $expected) {
        Http::fake(['https://example.com/stats.json' => Http::response(['queue' => ['size' => $size]])]);
        $check = ($this->check)();

        app(FetchDataSourceAction::class)->execute($this->source);

        $result = $check->results()->sole();
        expect($result->status)->toBe($expected)
            ->and($result->metrics)->toBe(['value' => $size]);
    })->with([
        'under both' => [10, HealthResultStatus::Ok],
        'over warn' => [600, HealthResultStatus::Warning],
        'over fail' => [1500, HealthResultStatus::Failed],
    ]);

    it('fails when the fetch fails or the path gives no number', function (mixed $body, int $status) {
        Http::fake(['https://example.com/stats.json' => Http::response($body, $status)]);
        $check = ($this->check)();

        app(FetchDataSourceAction::class)->execute($this->source);

        expect($check->results()->sole()->status)->toBe(HealthResultStatus::Failed);
    })->with([
        'a failed fetch' => [['queue' => ['size' => 1]], 503],
        'no number at the path' => [['queue' => []], 200],
    ]);

    it('judges each fetch once, and fails on its own schedule when the source stops being fetched', function () {
        Http::fake(['https://example.com/stats.json' => Http::response(['queue' => ['size' => 1]])]);
        $check = ($this->check)();
        app(FetchDataSourceAction::class)->execute($this->source);

        expect(app(RunHealthCheckAction::class)->execute($check->refresh()))->toBeNull();

        // Fetched every 5 minutes: stale after two intervals and a minute.
        $this->travel(12)->minutes();
        expect(app(RunHealthCheckAction::class)->execute($check->refresh())?->status)->toBe(HealthResultStatus::Failed);
    });

    it('waits for the first fetch of a new source', function () {
        expect(app(RunHealthCheckAction::class)->execute(($this->check)()))->toBeNull();
    });

    it('fails a connected check whose period was never pulled in time', function () {
        $source = DataSource::factory()->for($this->source->project)->connected()->create();
        $check = HealthCheck::factory()->for($source->project)
            ->monitored(HealthCheckType::DataSource, ['data_source' => $source->uuid, 'period' => '24h'])->create();

        expect(app(RunHealthCheckAction::class)->execute($check))->toBeNull();

        $this->travel(12)->minutes();
        expect(app(RunHealthCheckAction::class)->execute($check->refresh())?->message)->toBe('The data source has never been fetched.');
    });

    it('times a period never pulled from when it was first asked for, not from when the check was made', function () {
        $source = DataSource::factory()->for($this->source->project)->connected()->create();
        $check = HealthCheck::factory()->for($source->project)
            ->monitored(HealthCheckType::DataSource, ['data_source' => $source->uuid, 'period' => '24h'])->create(['created_at' => now()->subMonth()]);

        expect(app(RunHealthCheckAction::class)->execute($check))->toBeNull();
    });

    it('waits for the next pull of a period it starts reading, not timing it from one before it', function () {
        $source = DataSource::factory()->for($this->source->project)->connected()->create();
        // A screen showed it until hours ago, its last pull failing; nobody kept it pulled since.
        DataSourceWindow::factory()->forSource($source)->answered(42)->create([
            'period' => '24h', 'bucket' => 3600, 'requested_at' => now()->subHours(3), 'wanted_since' => now()->subHours(4), 'fetched_at' => now()->subHours(3),
            'error' => 'Timed out', 'failures' => 3,
        ]);

        $check = app(SaveHealthCheckAction::class)->execute($source->project, null, [
            'type' => 'data_source', 'name' => 'Orders', 'interval_minutes' => 5, 'degrade_after' => 2, 'recover_after' => 5, 'down_after' => 5,
        ], ['data_source' => $source->uuid, 'period' => '24h']);

        expect(app(RunHealthCheckAction::class)->execute($check))->toBeNull();
    });

    it('says why a connected source fails, however long it has failed', function () {
        $source = DataSource::factory()->for($this->source->project)->connected()->create();
        $check = HealthCheck::factory()->for($source->project)
            ->monitored(HealthCheckType::DataSource, ['data_source' => $source->uuid, 'period' => '24h'])->create();
        DataSourceWindow::factory()->forSource($source)->create([
            'period' => '24h', 'bucket' => 3600, 'requested_at' => now(), 'wanted_since' => now()->subDay(),
            'fetched_at' => now()->subDay(), 'error' => 'The app answered 500.',
        ]);

        expect(app(RunHealthCheckAction::class)->execute($check, afterFetch: true)?->message)->toBe('The data source failed: The app answered 500.');
    });

    it('keeps a connected source\'s period pulled and judges each pull', function () {
        config(['health.data_sources_allow_private' => false]);
        $source = DataSource::factory()->for($this->source->project)->connected()->create();
        $check = HealthCheck::factory()->for($source->project)
            ->monitored(HealthCheckType::DataSource, ['data_source' => $source->uuid, 'period' => '24h', 'operator' => '<', 'fail' => 50])->create();
        Http::fake(['https://app.example.com/*' => Http::response(['version' => 1, 'sources' => ['orders:total' => ['shape' => 'number', 'data' => 42]]])]);

        // Its first run asks for the period, as a screen would, and waits for the pull.
        expect(app(RunHealthCheckAction::class)->execute($check))->toBeNull();
        $window = DataSourceWindow::query()->where('data_source_id', $source->id)->sole();
        expect($window->period)->toBe('24h');

        app(FetchDataSourceWindowAction::class)->execute($window->id);

        $result = $check->results()->sole();
        expect($result->status)->toBe(HealthResultStatus::Failed)
            ->and($result->metrics)->toBe(['value' => 42]);
    });
});
