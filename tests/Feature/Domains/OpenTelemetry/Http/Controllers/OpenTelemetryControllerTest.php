<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiCallSource;
use App\Domains\Ai\Enums\AiCallStatus;
use App\Domains\Ai\Enums\AiOperation;
use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ingest\Http\Requests\IngestBatchRequest;
use App\Domains\Ingest\Jobs\ProcessIngestBatch;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Logs\Enums\LogLevel;
use App\Domains\Logs\Models\LogEntry;
use App\Domains\OpenTelemetry\Actions\IngestTracesAction;
use App\Domains\OpenTelemetry\Http\Controllers\OpenTelemetryController;
use App\Domains\OpenTelemetry\Http\Requests\OtlpExportRequest;
use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse;
use Opentelemetry\Proto\Logs\V1\SeverityNumber;
use Opentelemetry\Proto\Trace\V1\Span\SpanKind;
use Opentelemetry\Proto\Trace\V1\Status\StatusCode;
use Tests\Support\OpenTelemetry\BuildsOtlpPayloads;

covers(OpenTelemetryController::class, OtlpExportRequest::class, IngestTracesAction::class);

uses(BuildsOtlpPayloads::class);

beforeEach(function () {
    $this->project = Project::factory()->withToken('lj_srv_test-token')->create();
    $this->serverSpan = fn (array $attributes = []) => $this->protobufSpan(SpanKind::SPAN_KIND_SERVER, [
        'http.request.method' => 'GET',
        'http.route' => '/orders/{order}',
        'url.full' => 'https://shop.example.com/orders/42?token=secret',
        'server.address' => 'shop.example.com',
        'http.response.status_code' => 200,
        ...$attributes,
    ], 'GET /orders/{order}', 31.4);
});

describe('traces', function () {
    it('stores a server span as a request, with the environment, release and host of its resource', function () {
        $response = $this->otlp('traces', $this->protobufTraces([($this->serverSpan)()]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/x-protobuf');

        $request = RequestRun::sole();
        expect($response->getContent())->toBe((new ExportTraceServiceResponse())->serializeToString())
            ->and($request->method)->toBe('GET')
            ->and($request->route)->toBe('/orders/{order}')
            ->and($request->domain)->toBe('shop.example.com')
            ->and($request->status_code)->toBe(200)
            ->and($request->duration_us)->toBe(31_400)
            ->and($request->trace_id)->toBe($this->traceId)
            ->and($request->environment)->toBe('production')
            ->and($request->release)->toBe('2.4.1')
            ->and($request->host)->toBe('web-1')
            ->and($request->url)->not->toContain('secret');
    });

    it('reads the older HTTP conventions and deployment.environment, and groups a path without a route', function () {
        $span = $this->protobufSpan(SpanKind::SPAN_KIND_SERVER, ['http.method' => 'POST', 'http.target' => '/orders/42/items', 'http.host' => 'shop.example.com', 'http.status_code' => 201]);

        $this->otlp('traces', $this->protobufTraces([$span], ['deployment.environment' => 'staging']))->assertOk();

        $request = RequestRun::sole();
        expect($request->method)->toBe('POST')
            ->and($request->route)->toBe('/orders/{id}/items')
            ->and($request->status_code)->toBe(201)
            ->and($request->environment)->toBe('staging')
            ->and($request->release)->toBeNull();
    });

    it('answers a server span without a status code as its span status says', function (int $status, int $expected) {
        $span = $this->protobufSpan(SpanKind::SPAN_KIND_SERVER, ['http.request.method' => 'GET', 'http.route' => '/'], status: $status);

        $this->otlp('traces', $this->protobufTraces([$span]))->assertOk();

        expect(RequestRun::sole()->status_code)->toBe($expected);
    })->with([
        'unset' => [StatusCode::STATUS_CODE_UNSET, 200],
        'error' => [StatusCode::STATUS_CODE_ERROR, 500],
    ]);

    it('takes a gzip body', function () {
        $this->otlp('traces', $this->protobufTraces([($this->serverSpan)()]), gzip: true)->assertOk();

        expect(RequestRun::count())->toBe(1);
    });

    it('stores a database span as a query', function () {
        $span = $this->protobufSpan(SpanKind::SPAN_KIND_CLIENT, ['db.system.name' => 'postgresql', 'db.namespace' => 'shop', 'db.query.text' => 'select * from orders where id = 42'], 'SELECT orders', 1.5);

        $this->otlp('traces', $this->protobufTraces([$span]))->assertOk();

        $query = QueryRun::sole();
        expect($query->connection)->toBe('shop')
            ->and($query->sql)->toBe('select * from orders where id = ?')
            ->and($query->duration_us)->toBe(1_500)
            ->and($query->trace_id)->toBe($this->traceId);
    });

    it('stores an outgoing HTTP call and a Redis command as spans of the trace', function () {
        $this->otlp('traces', $this->protobufTraces([
            $this->protobufSpan(SpanKind::SPAN_KIND_CLIENT, ['http.request.method' => 'POST', 'url.full' => 'https://api.stripe.com/v1/charges?x=1', 'http.response.status_code' => 201], 'POST', 182.4),
            $this->protobufSpan(SpanKind::SPAN_KIND_CLIENT, ['db.system' => 'redis', 'db.statement' => 'get cart:7'], 'GET'),
            $this->protobufSpan(SpanKind::SPAN_KIND_INTERNAL, ['render' => 'view']),
        ]))->assertOk();

        $spans = DB::table('span_runs')->orderBy('id')->get();
        expect($spans)->toHaveCount(2)
            ->and([$spans[0]->kind, $spans[0]->name, $spans[0]->target, $spans[0]->duration_us])->toBe(['http', 'POST', 'api.stripe.com/v1/charges', 182_400])
            ->and(json_decode((string) $spans[0]->attributes, true))->toBe(['status' => 201])
            ->and([$spans[1]->kind, $spans[1]->name, $spans[1]->target])->toBe(['redis', 'GET', 'get cart:7']);
    });

    it('stores a consumer span as a job run', function (int $status, JobRunStatus $expected) {
        $span = $this->protobufSpan(SpanKind::SPAN_KIND_CONSUMER, ['messaging.system' => 'rabbitmq', 'messaging.destination.name' => 'emails'], 'emails process', 800, status: $status);

        $this->otlp('traces', $this->protobufTraces([$span]))->assertOk();

        $run = JobRun::sole();
        expect($run->status)->toBe($expected)
            ->and($run->name)->toBe('emails process')
            ->and($run->queue)->toBe('emails')
            ->and($run->duration_us)->toBe(800_000)
            ->and($run->started_at)->not->toBeNull();
    })->with([
        'a finished span' => [StatusCode::STATUS_CODE_UNSET, JobRunStatus::Processed],
        'a span that failed' => [StatusCode::STATUS_CODE_ERROR, JobRunStatus::Failed],
    ]);

    it('turns an exception event into an issue with the frames of its stack trace', function () {
        $span = $this->protobufSpan(SpanKind::SPAN_KIND_SERVER, ['http.request.method' => 'GET', 'http.route' => '/'], events: [['exception', [
            'exception.type' => 'App\\Exceptions\\OrderMissing',
            'exception.message' => 'Order 42 is gone',
            'exception.stacktrace' => "#0 /app/vendor/laravel/framework/src/Router.php(10): handle()\n#1 /app/app/Http/OrderController.php(31): App\\Http\\OrderController->show()\n#2 {main}",
        ]]]);

        $this->otlp('traces', $this->protobufTraces([$span]))->assertOk();

        $issue = Issue::sole();
        $occurrence = $issue->occurrences()->sole();
        expect($issue->title)->toBe('App\\Exceptions\\OrderMissing')
            ->and($issue->message)->toBe('Order 42 is gone')
            ->and($issue->source)->toBe(IssueSource::Server)
            ->and($occurrence->trace_id)->toBe($this->traceId)
            ->and($occurrence->environment)->toBe('production')
            ->and(array_column($occurrence->stacktrace ?? [], 'in_app'))->toBe([false, true])
            ->and($occurrence->stacktrace[1]['file'] ?? null)->toBe('/app/app/Http/OrderController.php');
    });

    it('keeps a stack trace it cannot read as text', function () {
        $span = $this->protobufSpan(SpanKind::SPAN_KIND_INTERNAL, [], events: [['exception', ['exception.type' => 'panic', 'exception.stacktrace' => "goroutine 1 [running]:\nmain.main()"]]]);

        $this->otlp('traces', $this->protobufTraces([$span]))->assertOk();

        expect(Issue::sole()->occurrences()->sole()->context['stacktrace'] ?? null)->toBe("goroutine 1 [running]:\nmain.main()");
    });

    it('ignores attributes and fields it does not know', function () {
        $span = ($this->serverSpan)(['app.tenant' => 'acme', 'app.flags' => true, 'app.ratio' => 0.5]);

        $this->otlp('traces', $this->protobufTraces([$span], [...$this->otlpResource(), 'k8s.pod.name' => 'web-1-abc']))->assertOk();

        expect(RequestRun::count())->toBe(1);
    });
});

describe('AI calls', function () {
    beforeEach(function () {
        $this->aiSpan = fn (array $attributes = [], int $status = StatusCode::STATUS_CODE_UNSET) => $this->protobufSpan(SpanKind::SPAN_KIND_CLIENT, [
            'gen_ai.provider.name' => 'openai',
            'gen_ai.operation.name' => 'chat',
            'gen_ai.request.model' => 'gpt-4.1',
            'gen_ai.response.model' => 'gpt-4.1-2025-04-14',
            'gen_ai.usage.input_tokens' => 1200,
            'gen_ai.usage.output_tokens' => 300,
            'gen_ai.usage.cache_read.input_tokens' => 800,
            'gen_ai.usage.cache_creation.input_tokens' => 100,
            'gen_ai.usage.reasoning.output_tokens' => 120,
            'http.request.method' => 'POST',
            'url.full' => 'https://api.openai.com/v1/chat/completions',
            ...$attributes,
        ], 'chat gpt-4.1', 1834.2, status: $status);
    });

    it('stores a GenAI span as an AI call, not as an outgoing HTTP call', function () {
        $this->otlp('traces', $this->protobufTraces([($this->aiSpan)([
            'lumberjack.ai.key.sha256' => hash('sha256', 'openai:sk-secret'),
            'lumberjack.ai.key.label' => 'prod',
            'lumberjack.ai.key.owner.type' => 'tenant',
            'lumberjack.ai.key.owner.id' => '42',
            'lumberjack.ai.actor.type' => 'App\\Models\\User',
            'lumberjack.ai.actor.id' => '7',
            'user.id' => '99',
        ])]))->assertOk();

        $call = AiCall::query()->sole();
        expect(DB::table('span_runs')->count())->toBe(0)
            ->and([$call->provider, $call->model, $call->operation])->toBe(['openai', 'gpt-4.1-2025-04-14', AiOperation::Text])
            ->and([$call->input_tokens, $call->output_tokens, $call->cache_read_tokens, $call->cache_write_tokens, $call->reasoning_tokens])->toBe([1200, 300, 800, 100, 120])
            ->and($call->duration_ms)->toBe(1834.2)
            ->and([$call->source, $call->status])->toBe([AiCallSource::Otel, AiCallStatus::Ok])
            ->and([$call->owner_type, $call->owner_id, $call->actor_type, $call->actor_id])->toBe(['tenant', '42', 'App\\Models\\User', '7'])
            ->and($call->fingerprint)->toBe(AiKey::query()->sole()->fingerprint)
            ->and(AiKey::query()->sole()->label)->toBe('prod')
            ->and([$call->trace_id, $call->environment, $call->release])->toBe([$this->traceId, 'production', '2.4.1']);
    });

    it('takes the signed in user as the actor when the app names none, and the older provider attribute', function () {
        $this->otlp('traces', $this->protobufTraces([($this->aiSpan)(['gen_ai.provider.name' => '', 'gen_ai.system' => 'anthropic', 'user.id' => '99'])]))->assertOk();

        $call = AiCall::query()->sole();
        expect($call->provider)->toBe('anthropic')
            ->and([$call->actor_type, $call->actor_id])->toBe(['user', '99']);
    });

    it('takes the signed in user as the actor when the app names only half of one', function (array $actor) {
        $this->otlp('traces', $this->protobufTraces([($this->aiSpan)([...$actor, 'user.id' => '99'])]))->assertOk();

        expect(AiCall::query()->sole()->only(['actor_type', 'actor_id']))->toBe(['actor_type' => 'user', 'actor_id' => '99']);
    })->with([
        'a type without an id' => [['lumberjack.ai.actor.type' => 'App\\Models\\User']],
        'an id without a type' => [['lumberjack.ai.actor.id' => '7']],
    ]);

    it('stores a failed GenAI span as a failed call, with its error type', function () {
        $this->otlp('traces', $this->protobufTraces([($this->aiSpan)(['error.type' => 'RateLimitError'], StatusCode::STATUS_CODE_ERROR)]))->assertOk();

        $call = AiCall::query()->sole();
        expect([$call->status, $call->error])->toBe([AiCallStatus::Error, 'RateLimitError']);
    });

    it('stores no call for agent and tool spans, whose tokens their model calls already count', function (string $operation) {
        $this->otlp('traces', $this->protobufTraces([($this->aiSpan)(['gen_ai.operation.name' => $operation])]))->assertOk();

        expect(AiCall::query()->count())->toBe(0)
            ->and(DB::table('span_runs')->count())->toBe(0);
    })->with(['invoke_agent', 'execute_tool', 'create_agent']);

    it('stores embeddings as embeddings', function () {
        $this->otlp('traces', $this->protobufTraces([($this->aiSpan)(['gen_ai.operation.name' => 'embeddings'])]))->assertOk();

        expect(AiCall::query()->sole()->operation)->toBe(AiOperation::Embeddings);
    });

    it('counts any other model call as text', function () {
        $this->otlp('traces', $this->protobufTraces([($this->aiSpan)(['gen_ai.operation.name' => 'generate_speech'])]))->assertOk();

        expect(AiCall::query()->sole()->operation)->toBe(AiOperation::Text);
    });

    it('reads the older prompt and completion token names', function () {
        $this->otlp('traces', $this->protobufTraces([($this->aiSpan)([
            'gen_ai.usage.input_tokens' => null,
            'gen_ai.usage.output_tokens' => null,
            'gen_ai.usage.prompt_tokens' => 70,
            'gen_ai.usage.completion_tokens' => 30,
        ])]))->assertOk();

        $call = AiCall::query()->sole();
        expect([$call->input_tokens, $call->output_tokens])->toBe([70, 30]);
    });

    it('queues the key\'s fingerprint, never its hash', function () {
        Queue::fake();
        $sha256 = hash('sha256', 'openai:sk-secret');

        $this->otlp('traces', $this->protobufTraces([($this->aiSpan)(['lumberjack.ai.key.sha256' => $sha256])]))->assertOk();

        Queue::assertPushed(ProcessIngestBatch::class, fn (ProcessIngestBatch $job): bool => ! str_contains((string) json_encode($job->payload), $sha256)
            && $job->payload['events'][0]['key']['fingerprint'] === hash_hmac('sha256', $sha256, $this->project->ai_key_secret));
    });
});

describe('OTLP/JSON', function () {
    it('reads hex ids and answers in JSON', function () {
        $start = now()->getTimestampMs() * 1_000_000;

        $this->otlp('traces', ['resourceSpans' => [[
            'resource' => ['attributes' => [['key' => 'service.version', 'value' => ['stringValue' => '2.4.1']]]],
            'scopeSpans' => [['scope' => (object) [], 'spans' => [[
                'traceId' => $this->traceId,
                'spanId' => $this->spanId,
                'name' => 'GET /',
                'kind' => 2,
                'startTimeUnixNano' => (string) $start,
                'endTimeUnixNano' => (string) ($start + 5_000_000),
                'attributes' => [['key' => 'http.request.method', 'value' => ['stringValue' => 'GET']], ['key' => 'http.route', 'value' => ['stringValue' => '/']]],
                'someFieldFromTheFuture' => 1,
            ]]]],
        ]]])->assertOk()->assertHeader('Content-Type', 'application/json')->assertExactJson([]);

        $request = RequestRun::sole();
        expect($request->trace_id)->toBe($this->traceId)
            ->and($request->duration_us)->toBe(5_000)
            ->and($request->release)->toBe('2.4.1');
    });

    it('reads a trace as the OpenTelemetry JavaScript SDK exports it', function () {
        $body = json_decode((string) file_get_contents(base_path('tests/Fixtures/OpenTelemetry/js-sdk-traces.json')), true);

        $this->otlp('traces', $body, gzip: true)->assertOk();

        $request = RequestRun::sole();
        $occurrence = Issue::sole()->occurrences()->sole();
        expect([$request->method, $request->route, $request->url, $request->status_code, $request->duration_us])->toBe(['GET', '/orders/:id', 'https://shop.example.com/orders/42', 500, 45_400])
            ->and([$request->environment, $request->release, $request->host])->toBe(['staging', '1.8.0', 'node-3'])
            ->and($request->trace_id)->toBe('0af7651916cd43dd8448eb211c80319c')
            ->and(Issue::sole()->title)->toBe('TypeError')
            ->and($occurrence->stacktrace[0] ?? null)->toMatchArray(['file' => '/srv/app/src/orders.js', 'line' => 18, 'function' => 'orderTotal', 'in_app' => true])
            ->and($occurrence->stacktrace[1]['in_app'] ?? null)->toBeFalse();
    });
});

describe('logs', function () {
    it('stores a log record as a log line without an issue', function () {
        $this->otlp('logs', $this->protobufLogs(SeverityNumber::SEVERITY_NUMBER_INFO, 'Order shipped', ['order.id' => 42]))->assertOk();

        $entry = LogEntry::sole();
        expect($entry->level)->toBe(LogLevel::Info)
            ->and($entry->message)->toBe('Order shipped')
            ->and($entry->context)->toBe(['order.id' => 42])
            ->and($entry->trace_id)->toBe($this->traceId)
            ->and($entry->environment)->toBe('production')
            ->and(Issue::count())->toBe(0);
    });

    it('turns an error record into a log line and an issue', function () {
        $this->otlp('logs', $this->protobufLogs(SeverityNumber::SEVERITY_NUMBER_ERROR, 'Payment declined'))->assertOk();

        expect(LogEntry::sole()->level)->toBe(LogLevel::Error)
            ->and(Issue::sole()->source)->toBe(IssueSource::Log)
            ->and(Issue::sole()->message)->toBe('Payment declined');
    });

    it('turns an error record with an exception into an issue for that exception', function () {
        $this->otlp('logs', $this->protobufLogs(SeverityNumber::SEVERITY_NUMBER_FATAL, 'Unhandled', [
            'exception.type' => 'ValueError',
            'exception.message' => 'bad total',
            'exception.stacktrace' => "Traceback (most recent call last):\n  File \"/app/shop/views.py\", line 12, in checkout\n    total()\n  File \"/app/shop/orders.py\", line 40, in total\n    raise ValueError('bad total')",
        ]))->assertOk();

        $issue = Issue::sole();
        expect(LogEntry::sole()->level)->toBe(LogLevel::Critical)
            ->and($issue->title)->toBe('ValueError')
            ->and($issue->occurrences()->sole()->stacktrace[0]['file'] ?? null)->toBe('/app/shop/orders.py');
    });
});

describe('metrics', function () {
    it('takes metrics and drops them', function () {
        $this->otlp('metrics', ['resourceMetrics' => []])->assertOk()->assertExactJson([]);
    });
});

describe('what it refuses', function () {
    it('rejects an unknown token', function () {
        $this->otlp('traces', $this->protobufTraces([]), token: 'nope')->assertUnauthorized();
    });

    it('rejects a body that is not an export request', function (string|array $body) {
        $this->otlp('traces', $body)->assertBadRequest();

        expect(RequestRun::count())->toBe(0);
    })->with([
        'protobuf garbage' => ["\xff\xff\xff\xff not protobuf"],
        'a JSON list' => [[1, 2, 3]],
        'JSON with fields of the wrong type' => [['resourceSpans' => 'nope']],
    ]);

    it('answers an error in protobuf as a status message', function () {
        $response = $this->otlp('traces', "\xff\xff\xff\xff not protobuf")->assertBadRequest()->assertHeader('Content-Type', 'application/x-protobuf');

        expect($response->getContent())->toBe("\x12".chr(strlen(__('opentelemetry.errors.undecodable'))).__('opentelemetry.errors.undecodable'));
    });

    it('rejects a body that is not gzip', function () {
        $this->call('POST', '/api/v1/opentelemetry/v1/traces', [], [], [], ['CONTENT_TYPE' => 'application/x-protobuf', 'HTTP_CONTENT_ENCODING' => 'gzip', 'HTTP_AUTHORIZATION' => 'Bearer lj_srv_test-token'], 'plain')
            ->assertBadRequest();
    });

    it('rejects a body that is too large', function () {
        $this->otlp('traces', str_repeat("\0", IngestBatchRequest::MAX_BYTES + 1))->assertStatus(413);
    });

    it('rejects a gzip body that says it inflates past the limit, before inflating it', function () {
        // A small body whose trailer claims too much: what the check reads, without 20 MB in memory.
        $body = substr(gzencode('{}'), 0, -4).pack('V', OtlpExportRequest::MAX_INFLATED_BYTES + 1);

        $this->call('POST', '/api/v1/opentelemetry/v1/traces', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_CONTENT_ENCODING' => 'gzip', 'HTTP_AUTHORIZATION' => 'Bearer lj_srv_test-token'], $body)
            ->assertStatus(413);
    });

    it('rejects an export with more events than an agent batch may hold, keeping none', function () {
        $spans = array_fill(0, IngestBatchRequest::MAX_EVENTS + 1, $this->protobufSpan(SpanKind::SPAN_KIND_SERVER, ['http.request.method' => 'GET']));

        $this->otlp('traces', $this->protobufTraces($spans))->assertStatus(413);

        expect(RequestRun::count())->toBe(0);
    });

    it('rejects another content type', function () {
        $this->call('POST', '/api/v1/opentelemetry/v1/logs', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer lj_srv_test-token'], 'hello')
            ->assertStatus(415);
    });
});
