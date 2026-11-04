<?php

declare(strict_types=1);

use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckHost;
use App\Domains\Ingest\Http\Controllers\IngestController;
use App\Domains\Ingest\Http\Middleware\AuthenticateProjectToken;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Logs\Models\LogEntry;
use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Data\AgentClient;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Ingest\BuildsIngestPayloads;

covers(IngestController::class);

uses(BuildsIngestPayloads::class);

beforeEach(function () {
    $this->project = Project::factory()->withToken('lj_srv_test-token')->create(['allowed_origins' => ['https://app.example.test']]);
    $this->auth = ['Authorization' => 'Bearer lj_srv_test-token'];
});

describe('server ingest', function () {
    it('rejects an unknown token', function () {
        $this->postJson('/api/ingest', $this->batch([]), ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    });

    it('turns an address away unchecked after many unknown tokens', function () {
        foreach (range(1, AuthenticateProjectToken::FAILURES_PER_MINUTE) as $i) {
            $this->postJson('/api/ingest', [], ['Authorization' => 'Bearer nope-'.$i])->assertUnauthorized();
        }

        $this->postJson('/api/ingest', [], ['Authorization' => 'Bearer nope-again'])->assertTooManyRequests();
    });

    it('stores every event type of a batch', function () {
        $this->postJson('/api/ingest', $this->batch([
            ['type' => 'request', 'trace_id' => 't1', 'timestamp' => now()->getTimestamp(), 'method' => 'get', 'route' => '/dashboard', 'url' => 'https://x.test/dashboard', 'status' => 200, 'duration_ms' => 12.5],
            ['type' => 'query', 'trace_id' => 't1', 'timestamp' => now()->getTimestamp(), 'connection' => 'mysql', 'sql' => 'select * from users where id in (?, ?, ?)', 'duration_ms' => 1.2],
            ['type' => 'log', 'trace_id' => 't1', 'timestamp' => now()->getTimestamp(), 'channel' => 'default', 'level' => 'warning', 'message' => 'Slow thing'],
            ['type' => 'job', 'status' => 'processed', 'uuid' => '0d9c7a1e-1111-4222-8333-944455556666', 'name' => 'App\\Jobs\\Foo', 'queue' => 'default', 'attempt' => 1, 'duration_ms' => 800, 'timestamp' => now()->getTimestamp()],
            $this->exceptionEvent(),
        ]), $this->auth)->assertStatus(202)->assertJson(['accepted' => 5]);

        $request = RequestRun::sole();
        expect($request->method)->toBe('GET')
            ->and($request->duration_us)->toBe(12_500)
            ->and($request->environment)->toBe('production')
            ->and(QueryRun::sole()->sql)->toBe('select * from users where id in (?)')
            ->and(LogEntry::sole()->message)->toBe('Slow thing')
            ->and(JobRun::sole()->status)->toBe(JobRunStatus::Processed)
            ->and(Issue::sole()->source)->toBe(IssueSource::Server);
    });

    it('turns down a batch with an event of no known type, in one pass over the events', function () {
        $this->postJson('/api/ingest', $this->batch([['type' => 'log', 'message' => 'ok'], ['type' => 'bogus']]), ['Authorization' => 'Bearer lj_srv_test-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('events');
    });

    it('stores spans on their trace, and skips a span of a kind it does not know', function () {
        $this->postJson('/api/ingest', $this->batch([
            ['type' => 'span', 'trace_id' => 't1', 'timestamp' => now()->getTimestamp(), 'kind' => 'http', 'name' => 'POST', 'target' => 'api.stripe.com/v1/charges', 'duration_ms' => 182.4, 'attributes' => ['status' => 201]],
            ['type' => 'span', 'trace_id' => 't1', 'timestamp' => now()->getTimestamp(), 'kind' => 'cache', 'name' => 'miss', 'target' => 'cart:7'],
            ['type' => 'span', 'trace_id' => 't1', 'timestamp' => now()->getTimestamp(), 'kind' => 'telepathy', 'name' => 'x', 'target' => 'y'],
        ]), $this->auth)->assertStatus(202);

        $spans = DB::table('span_runs')->orderBy('id')->get();
        expect($spans)->toHaveCount(2)
            ->and($spans[0]->duration_us)->toBe(182_400)
            ->and(json_decode((string) $spans[0]->attributes, true))->toBe(['status' => 201])
            ->and($spans[1]->duration_us)->toBeNull();
    });

    it('scrubs secrets from exception context', function () {
        $this->postJson('/api/ingest', $this->batch([$this->exceptionEvent()]), $this->auth)->assertStatus(202);

        expect(Issue::sole()->occurrences()->sole()->context['request']['headers']['Authorization'])->toBe('[filtered]');
    });

    it('masks secrets in stored request URLs', function () {
        $this->postJson('/api/ingest', $this->batch([
            ['type' => 'request', 'trace_id' => 't1', 'timestamp' => now()->getTimestamp(), 'method' => 'get', 'route' => '/files', 'url' => 'https://x.test/files?signature=abc123&page=2', 'status' => 200, 'duration_ms' => 5],
        ]), $this->auth)->assertStatus(202);

        expect(RequestRun::sole()->url)->toBe('https://x.test/files?signature=[filtered]&page=2');
    });

    it('stores the domain and route name a request event carries, else the domain of its URL', function () {
        $request = ['type' => 'request', 'timestamp' => now()->getTimestamp(), 'method' => 'GET', 'route' => '/orders', 'status' => 200, 'duration_ms' => 5];

        $this->postJson('/api/ingest', $this->batch([
            [...$request, 'url' => 'https://shop.example.com/orders', 'domain' => 'Shop.Example.com', 'route_name' => 'orders.index'],
            [...$request, 'url' => 'https://user:secret@API.example.com:8443/orders?page=2'],
            [...$request, 'url' => '/orders'],
        ]), $this->auth)->assertStatus(202);

        expect(RequestRun::query()->orderBy('id')->get(['domain', 'route_name'])->toArray())->toBe([
            ['domain' => 'shop.example.com', 'route_name' => 'orders.index'],
            ['domain' => 'api.example.com:8443', 'route_name' => null],
            ['domain' => null, 'route_name' => null],
        ]);
    });

    it('accepts a gzip compressed body', function () {
        $body = gzencode(json_encode($this->batch([$this->exceptionEvent()])));

        $this->call('POST', '/api/ingest', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer lj_srv_test-token',
            'HTTP_CONTENT_ENCODING' => 'gzip',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(202);

        expect(Issue::count())->toBe(1);
    });

    it('rejects a batch with an unknown event type', function () {
        $this->postJson('/api/ingest', $this->batch([['type' => 'telepathy']]), $this->auth)->assertUnprocessable();
    });

    it('records an agent heartbeat with what the agent is', function () {
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'client' => [
            'sdk' => ['name' => 'lumberjack.php.laravel', 'version' => '0.2.0'],
            'agent' => ['name' => 'lumberjack-agent-php', 'version' => '0.2.0'],
            'runtime' => ['name' => 'php', 'version' => '8.5.1'],
            'framework' => ['name' => 'laravel', 'version' => '13.2.0'],
        ]], $this->auth)->assertOk();

        expect($this->project->agents()->sole())
            ->host->toBe('web-2')
            ->sdk->toBe('lumberjack.php.laravel')
            ->sdk_version->toBe('0.2.0')
            ->agent->toBe('lumberjack-agent-php')
            ->runtime_version->toBe('8.5.1')
            ->framework->toBe('laravel')
            ->and(AgentClient::fromAgent($this->project->agents()->sole())->label())->toBe('Laravel 0.2.0 · PHP 8.5.1');
    });

    it('reads an older agent\'s version as the Laravel SDK', function () {
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'version' => '0.1.0'], $this->auth)->assertOk();

        expect($this->project->agents()->sole())->sdk->toBe('lumberjack.php.laravel')->sdk_version->toBe('0.1.0');
    });

    it('refuses a client name that is no plain id', function () {
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'client' => ['sdk' => ['name' => 'Laravel <b>']]], $this->auth)->assertUnprocessable();
    });

    it('keeps named agents on one host apart', function () {
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'name' => 'web'], $this->auth)->assertOk();
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'name' => 'queue'], $this->auth)->assertOk();
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'name' => 'queue'], $this->auth)->assertOk();

        expect($this->project->agents()->orderBy('name')->pluck('name')->all())->toBe(['queue', 'web']);
    });

    it('answers a heartbeat with the health states of its environment', function () {
        $this->freezeTime();
        $check = fn (string $environment): HealthCheck => HealthCheck::factory()->for($this->project)->create(['environment' => $environment, 'slug' => 'disk:s3', 'status' => HealthStatus::Down, 'status_changed_at' => now()]);
        HealthCheckHost::query()->create(['health_check_id' => $check('production')->id, 'host' => 'web-2', 'status' => HealthStatus::Down, 'last_message' => 'No space', 'last_result_at' => now()]);
        $check('staging');

        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'environment' => 'production'], $this->auth)->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'health.checks')
            ->assertJsonPath('health.checks.0', ['slug' => 'disk:s3', 'environment' => 'production', 'state' => 'down', 'since' => now()->toIso8601String(), 'message' => 'No space']);

        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2'], $this->auth)->assertJsonCount(2, 'health.checks');
    });

    it('stores the queue sizes a heartbeat reports', function () {
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'queues' => [
            ['connection' => 'redis', 'queue' => 'default', 'size' => 12, 'oldest_age_s' => 40, 'workers' => 3],
            ['connection' => 'redis', 'queue' => 'emails', 'size' => 0, 'oldest_age_s' => null],
        ]], $this->auth)->assertOk();

        expect(QueueSample::query()->orderBy('queue')->get(['host', 'connection', 'queue', 'size', 'oldest_age_s', 'workers'])->toArray())->toBe([
            ['host' => 'web-2', 'connection' => 'redis', 'queue' => 'default', 'size' => 12, 'oldest_age_s' => 40, 'workers' => 3],
            ['host' => 'web-2', 'connection' => 'redis', 'queue' => 'emails', 'size' => 0, 'oldest_age_s' => null, 'workers' => null],
        ]);
    });

    it('stores how busy the agent\'s host is, and nothing when it tells nothing', function () {
        $metrics = ['cpu_load_1m' => 0.42, 'cpu_cores' => 1.5, 'memory_used_bytes' => 268435456, 'memory_total_bytes' => 536870912, 'disk_used_bytes' => 10, 'disk_total_bytes' => 100, 'php_memory_peak_bytes' => 2097152];
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'metrics' => $metrics], $this->auth)->assertOk();
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'metrics' => ['cpu_load_1m' => null]], $this->auth)->assertOk();

        expect(HostSample::query()->sole()->only(array_keys($metrics)))->toBe($metrics)
            ->and(HostSample::query()->sole()->project_agent_id)->toBe($this->project->agents()->sole()->id);
    });

    it('refuses a queue size that is no count', function () {
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-2', 'queues' => [['connection' => 'redis', 'queue' => 'default', 'size' => -1]]], $this->auth)->assertUnprocessable();
    });
});

describe('grouping and regressions', function () {
    it('groups the same exception into one issue', function () {
        $this->postJson('/api/ingest', $this->batch([$this->exceptionEvent(message: 'Order 12 failed'), $this->exceptionEvent(message: 'Order 99 failed')]), $this->auth);

        $issue = Issue::sole();
        expect($issue->occurrences_count)->toBe(2)
            ->and($issue->occurrences()->count())->toBe(2);
    });

    it('re-opens a resolved issue as a regression', function () {
        $this->postJson('/api/ingest', $this->batch([$this->exceptionEvent()]), $this->auth);
        Issue::sole()->update(['status' => IssueStatus::Resolved, 'resolved_at' => now()]);

        $this->postJson('/api/ingest', $this->batch([$this->exceptionEvent()]), $this->auth);

        $issue = Issue::sole();
        expect($issue->status)->toBe(IssueStatus::Open)
            ->and($issue->regressed_at)->not->toBeNull()
            ->and($issue->resolved_at)->toBeNull();
    });

    it('keeps an ignored issue ignored', function () {
        $this->postJson('/api/ingest', $this->batch([$this->exceptionEvent()]), $this->auth);
        Issue::sole()->update(['status' => IssueStatus::Ignored, 'ignored_at' => now()]);

        $this->postJson('/api/ingest', $this->batch([$this->exceptionEvent()]), $this->auth);

        expect(Issue::sole()->status)->toBe(IssueStatus::Ignored)
            ->and(Issue::sole()->occurrences_count)->toBe(2);
    });
});

describe('logs', function () {
    it('keeps a line for an unknown channel in default with the name it was sent to', function () {
        $this->postJson('/api/ingest', $this->batch([
            ['type' => 'log', 'channel' => 'mailchimp-sync', 'level' => 'info', 'message' => 'Synced'],
        ]), $this->auth);

        $entry = LogEntry::sole();
        expect($entry->channel->name)->toBe(LogChannel::DEFAULT)
            ->and($entry->sent_channel)->toBe('mailchimp-sync');
    });

    it('makes an issue of a line at error and above', function () {
        $this->postJson('/api/ingest', $this->batch([
            ['type' => 'log', 'channel' => 'default', 'level' => 'error', 'message' => 'Capture failed for order 88213'],
            ['type' => 'log', 'channel' => 'default', 'level' => 'error', 'message' => 'Capture failed for order 11'],
        ]), $this->auth);

        $issue = Issue::sole();
        expect($issue->source)->toBe(IssueSource::Log)
            ->and($issue->occurrences_count)->toBe(2);
    });

    it('keeps the sent channel name on an issue from an unknown channel', function () {
        $this->postJson('/api/ingest', $this->batch([
            ['type' => 'log', 'channel' => 'payments', 'level' => 'error', 'message' => 'Payment provider timed out'],
        ]), $this->auth);

        expect(Occurrence::sole()->context['log'])->toMatchArray(['channel' => LogChannel::DEFAULT, 'sent_channel' => 'payments']);
    });
});

describe('jobs', function () {
    it('links a failed attempt to the issue its exception became', function () {
        $uuid = '0d9c7a1e-1111-4222-8333-944455556666';

        $this->postJson('/api/ingest', $this->batch([
            ['type' => 'job', 'status' => 'queued', 'uuid' => $uuid, 'name' => 'App\\Jobs\\Foo', 'queue' => 'default', 'timestamp' => now()->getTimestamp()],
            ['type' => 'job', 'status' => 'processing', 'uuid' => $uuid, 'name' => 'App\\Jobs\\Foo', 'queue' => 'default', 'attempt' => 1, 'timestamp' => now()->getTimestamp()],
            ['type' => 'job', 'status' => 'failed', 'uuid' => $uuid, 'name' => 'App\\Jobs\\Foo', 'queue' => 'default', 'attempt' => 1, 'duration_ms' => 50, 'timestamp' => now()->getTimestamp(), 'exception' => $this->exceptionEvent()],
        ]), $this->auth);

        $run = JobRun::sole();
        expect($run->status)->toBe(JobRunStatus::Failed)
            ->and($run->queued_at)->not->toBeNull()
            ->and($run->started_at)->not->toBeNull()
            ->and($run->occurrence_id)->toBe(Issue::sole()->occurrences()->sole()->id);
    });
});

describe('scheduled tasks', function () {
    it('records a scheduled task run sent by the agent', function () {
        $this->postJson('/api/ingest', $this->batch([
            ['type' => 'schedule', 'status' => 'finished', 'uuid' => '5c1e9b8a-1111-4222-8333-944455556666', 'name' => 'artisan backup:run', 'expression' => '0 3 * * *', 'exit_code' => 0, 'duration_ms' => 900, 'timestamp' => now()->getTimestamp()],
        ]), $this->auth)->assertStatus(202);

        expect(ScheduledTask::sole()->name)->toBe('artisan backup:run')
            ->and(ScheduledTask::sole()->last_status)->toBe(ScheduledRunStatus::Finished);
    });
});

describe('browser ingest', function () {
    it('checks the key before it reads the body', function () {
        $this->call('POST', '/api/ingest/browser?key=nope', [], [], [], ['HTTP_CONTENT_ENCODING' => 'gzip', 'HTTP_ORIGIN' => 'https://app.example.test', 'CONTENT_TYPE' => 'text/plain'], 'not gzip')
            ->assertForbidden();
    });

    it('refuses a compressed body even with the right key', function () {
        $this->call('POST', '/api/ingest/browser?key='.$this->project->public_key, [], [], [], ['HTTP_CONTENT_ENCODING' => 'gzip', 'HTTP_ORIGIN' => 'https://app.example.test', 'CONTENT_TYPE' => 'text/plain'], (string) gzencode('{}'))
            ->assertStatus(415);
    });

    it('limits an address whatever key it makes up', function () {
        $limits = RateLimiter::limiter('ingest-browser')(Request::create('/api/ingest/browser?key=made-up', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']));

        expect(collect($limits)->pluck('key')->all())->toContain('ingest-browser-ip:203.0.113.9');
    });

    it('refuses an origin the project does not allow', function () {
        $this->postJson('/api/ingest/browser?key='.$this->project->public_key, $this->batch([$this->exceptionEvent('TypeError')]), ['Origin' => 'https://evil.test'])
            ->assertForbidden();
    });

    it('accepts errors from an allowed origin with CORS headers', function () {
        $this->postJson('/api/ingest/browser?key='.$this->project->public_key, $this->batch([$this->exceptionEvent('TypeError')]), ['Origin' => 'https://app.example.test'])
            ->assertStatus(202)
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test');

        expect(Issue::sole()->source)->toBe(IssueSource::Browser);
    });

    it('answers a bot like anyone else but stores nothing', function (string $agent) {
        $this->postJson('/api/ingest/browser?key='.$this->project->public_key, $this->batch([$this->exceptionEvent('TypeError')]), ['Origin' => 'https://app.example.test', 'User-Agent' => $agent])
            ->assertStatus(202)
            ->assertJson(['accepted' => 0]);

        expect(Issue::count())->toBe(0);
    })->with([
        'Googlebot' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'headless Chrome' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36',
        'Lighthouse' => 'Mozilla/5.0 (Linux; Android 11) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36 Chrome-Lighthouse',
        'no user agent' => '',
    ]);

    it('stores errors from a real browser', function () {
        $this->postJson('/api/ingest/browser?key='.$this->project->public_key, $this->batch([$this->exceptionEvent('TypeError')]), ['Origin' => 'https://app.example.test', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'])
            ->assertJson(['accepted' => 1]);

        expect(Issue::count())->toBe(1);
    });

    it('stores web vitals from a browser, drops absurd values and groups ids in the path', function () {
        $this->postJson('/api/ingest/browser?key='.$this->project->public_key, $this->batch([
            ['type' => 'vital', 'route' => '/orders/42/items/0d9c7a1e-1111-4222-8333-944455556666', 'url' => 'https://app.example.test/orders/42', 'lcp_ms' => 2400.5, 'inp_ms' => -5, 'cls' => 0.12, 'ttfb_ms' => 90_000, 'fcp_ms' => 800, 'timestamp' => now()->getTimestamp()],
            ['type' => 'vital', 'route' => '/empty', 'lcp_ms' => null, 'cls' => 99],
        ]), ['Origin' => 'https://app.example.test', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'])
            ->assertStatus(202);

        $vital = DB::table('web_vitals')->sole();
        expect($vital->route)->toBe('/orders/{id}/items/{id}')
            ->and($vital->lcp_us)->toBe(2_400_500)
            ->and($vital->inp_us)->toBeNull()
            ->and($vital->ttfb_us)->toBeNull()
            ->and($vital->fcp_us)->toBe(800_000)
            ->and((float) $vital->cls)->toBe(0.12)
            ->and($vital->environment)->toBe('production');
    });

    it('does not accept non-error events from a browser', function () {
        $this->postJson('/api/ingest/browser?key='.$this->project->public_key, $this->batch([['type' => 'log', 'message' => 'x']]), ['Origin' => 'https://app.example.test'])
            ->assertUnprocessable();
    });
});

describe('extra secret fields', function () {
    it('masks the project\'s own secret fields as well as the defaults', function () {
        $this->project->update(['scrub_keys' => ['cpr']]);
        $event = $this->exceptionEvent();
        $event['context']['session'] = ['customer_cpr' => '010190-1234', 'cart_id' => 7];

        $this->postJson('/api/ingest', $this->batch([$event]), $this->auth)->assertStatus(202);

        $context = Issue::sole()->occurrences()->sole()->context;
        expect($context['session']['customer_cpr'])->toBe('[filtered]')
            ->and($context['session']['cart_id'])->toBe(7)
            ->and($context['request']['headers']['Authorization'])->toBe('[filtered]');
    });

    it('saves extra secret fields from the settings page', function () {
        $user = App\Models\User::factory()->inTeam($this->project->team)->create();

        $this->actingAs($user)->put(route('projects.settings.update', $this->project), [
            'name' => 'Shop', 'slug' => 'shop', 'allowed_origins' => [], 'scrub_keys' => ['CPR', 'iban'],
        ])->assertSessionHasNoErrors();

        expect($this->project->refresh()->scrub_keys)->toBe(['cpr', 'iban']);
    });
});
