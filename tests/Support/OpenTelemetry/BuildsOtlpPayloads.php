<?php

declare(strict_types=1);

namespace Tests\Support\OpenTelemetry;

use Illuminate\Testing\TestResponse;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Logs\V1\LogRecord;
use Opentelemetry\Proto\Logs\V1\ResourceLogs;
use Opentelemetry\Proto\Logs\V1\ScopeLogs;
use Opentelemetry\Proto\Resource\V1\Resource;
use Opentelemetry\Proto\Trace\V1\ResourceSpans;
use Opentelemetry\Proto\Trace\V1\ScopeSpans;
use Opentelemetry\Proto\Trace\V1\Span;
use Opentelemetry\Proto\Trace\V1\Span\Event;
use Opentelemetry\Proto\Trace\V1\Status;

trait BuildsOtlpPayloads
{
    public string $traceId = '5b8efff798038103d269b633813fc60c';

    public string $spanId = 'eee19b7ec3c1b174';

    /**
     * The resource every payload here comes from.
     *
     * @return array<string, mixed>
     */
    public function otlpResource(): array
    {
        return ['deployment.environment.name' => 'production', 'service.version' => '2.4.1', 'host.name' => 'web-1', 'service.name' => 'shop'];
    }

    /**
     * One span as the generated protobuf class, its times measured from now.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{string, array<string, mixed>}>  $events  name and attributes
     */
    public function protobufSpan(int $kind, array $attributes, string $name = 'span', float $durationMs = 12.5, array $events = [], int $status = 0): Span
    {
        $start = now()->getTimestampMs() * 1_000_000;

        return new Span([
            'trace_id' => (string) hex2bin($this->traceId),
            'span_id' => (string) hex2bin($this->spanId),
            'name' => $name,
            'kind' => $kind,
            'start_time_unix_nano' => $start,
            'end_time_unix_nano' => $start + (int) ($durationMs * 1_000_000),
            'attributes' => $this->keyValues($attributes),
            'events' => array_map(fn (array $event): Event => new Event(['name' => $event[0], 'time_unix_nano' => $start, 'attributes' => $this->keyValues($event[1])]), $events),
            'status' => new Status(['code' => $status]),
        ]);
    }

    /**
     * @param  list<Span>  $spans
     * @param  array<string, mixed>|null  $resource
     */
    public function protobufTraces(array $spans, ?array $resource = null): string
    {
        return (new ExportTraceServiceRequest(['resource_spans' => [new ResourceSpans([
            'resource' => new Resource(['attributes' => $this->keyValues($resource ?? $this->otlpResource())]),
            'scope_spans' => [new ScopeSpans(['spans' => $spans])],
        ])]]))->serializeToString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function protobufLogs(int $severity, string $body, array $attributes = []): string
    {
        $record = new LogRecord([
            'time_unix_nano' => now()->getTimestampMs() * 1_000_000,
            'severity_number' => $severity,
            'body' => $this->anyValue($body),
            'attributes' => $this->keyValues($attributes),
            'trace_id' => (string) hex2bin($this->traceId),
            'span_id' => (string) hex2bin($this->spanId),
        ]);

        return (new ExportLogsServiceRequest(['resource_logs' => [new ResourceLogs([
            'resource' => new Resource(['attributes' => $this->keyValues($this->otlpResource())]),
            'scope_logs' => [new ScopeLogs(['log_records' => [$record]])],
        ])]]))->serializeToString();
    }

    /**
     * Posts a body as an exporter does.
     *
     * @param  array<string, mixed>|string  $body  an array is sent as OTLP/JSON, a string as protobuf
     * @return TestResponse<\Illuminate\Http\Response>
     */
    public function otlp(string $signal, array|string $body, bool $gzip = false, string $token = 'lj_srv_test-token'): TestResponse
    {
        $content = is_string($body) ? $body : (string) json_encode($body);
        $headers = [
            'CONTENT_TYPE' => is_string($body) ? 'application/x-protobuf' : 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ];

        if ($gzip) {
            $content = (string) gzencode($content);
            $headers['HTTP_CONTENT_ENCODING'] = 'gzip';
        }

        return $this->call('POST', '/api/v1/opentelemetry/v1/'.$signal, [], [], [], $headers, $content);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<KeyValue>
     */
    private function keyValues(array $attributes): array
    {
        return array_map(fn (string $key): KeyValue => new KeyValue(['key' => $key, 'value' => $this->anyValue($attributes[$key])]), array_keys($attributes));
    }

    private function anyValue(mixed $value): AnyValue
    {
        return new AnyValue(match (true) {
            is_bool($value) => ['bool_value' => $value],
            is_int($value) => ['int_value' => $value],
            is_float($value) => ['double_value' => $value],
            default => ['string_value' => (string) $value],
        });
    }
}
