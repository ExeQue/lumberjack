<?php

declare(strict_types=1);

namespace App\Domains\OpenTelemetry\Actions;

use App\Domains\Ai\Enums\AiCallSource;
use App\Domains\Ai\Enums\AiCallStatus;
use App\Domains\Ai\Enums\AiOperation;
use App\Domains\Ingest\Actions\ProcessIngestBatchAction;
use App\Domains\OpenTelemetry\Actions\Concerns\MapsOtlpData;
use App\Domains\OpenTelemetry\Support\StackTraceParser;
use App\Domains\Performance\Enums\SpanKind as LumberjackSpanKind;
use App\Domains\Projects\Models\Project;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Trace\V1\Span;
use Opentelemetry\Proto\Trace\V1\Span\SpanKind;
use Opentelemetry\Proto\Trace\V1\Status\StatusCode;

/**
 * Spans sent over OTLP, as the request, job, query, span, AI call and exception events of an ingest batch.
 * See docs/opentelemetry.md for the mapping.
 */
class IngestTracesAction
{
    use MapsOtlpData;

    /** GenAI operations that wrap model calls rather than make one. */
    public const array AI_WRAPPER_OPERATIONS = ['invoke_agent', 'create_agent', 'execute_tool'];

    public function __construct(
        private readonly StackTraceParser $stackTraceParser,
    ) {
    }

    public function execute(Project $project, ExportTraceServiceRequest $request): void
    {
        $parts = [];

        foreach ($request->getResourceSpans() as $resourceSpans) {
            $events = [];

            foreach ($resourceSpans->getScopeSpans() as $scopeSpans) {
                foreach ($scopeSpans->getSpans() as $span) {
                    array_push($events, ...$this->events($span));
                }
            }

            $parts[] = [$this->envelope($resourceSpans->getResource()), $events];
        }

        $this->dispatch($project, $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(Span $span): array
    {
        $attributes = $this->attributes($span->getAttributes());
        $traceId = $this->hex($span->getTraceId());
        $start = $this->seconds($span->getStartTimeUnixNano());
        $base = ['trace_id' => $traceId, 'timestamp' => $start, 'duration_ms' => max(0, (int) $span->getEndTimeUnixNano() - (int) $span->getStartTimeUnixNano()) / 1e6];

        $method = $this->first($attributes, 'http.request.method', 'http.method');
        $database = $this->first($attributes, 'db.system.name', 'db.system');
        $statement = $this->first($attributes, 'db.query.text', 'db.statement');
        $status = (int) $this->first($attributes, 'http.response.status_code', 'http.status_code');

        $events = match (true) {
            // A model call over HTTP is an AI call, not an outgoing request.
            $this->first($attributes, 'gen_ai.provider.name', 'gen_ai.system', 'gen_ai.operation.name') !== null => $this->aiCall($span, $base, $attributes),
            // A server span without a status code answered as its own status says.
            $span->getKind() === SpanKind::SPAN_KIND_SERVER && $method !== null => [$this->request(
                $base,
                $attributes,
                $method,
                $status ?: ($span->getStatus()?->getCode() === StatusCode::STATUS_CODE_ERROR ? 500 : 200)
            )],
            $span->getKind() === SpanKind::SPAN_KIND_CONSUMER => $this->job($span, $base, $attributes),
            $database === 'redis' => [[...$base, 'type' => 'span', 'kind' => LumberjackSpanKind::Redis->value,
                'name' => strtoupper(strtok($statement ?? $span->getName(), ' ') ?: 'REDIS'), 'target' => $statement ?? $span->getName()]],
            $database !== null && $statement !== null => [[...$base, 'type' => 'query',
                'connection' => $this->first($attributes, 'db.namespace', 'db.name') ?? $database, 'sql' => $statement]],
            $span->getKind() === SpanKind::SPAN_KIND_CLIENT && $method !== null => [[...$base, 'type' => 'span', 'kind' => LumberjackSpanKind::Http->value,
                'name' => strtoupper($method), 'target' => $this->target($this->url($attributes)), 'attributes' => array_filter(['status' => $status])]],
            default => [],
        };

        foreach ($span->getEvents() as $event) {
            if ($event->getName() === 'exception') {
                $events[] = [
                    'type' => 'exception',
                    'trace_id' => $traceId,
                    'timestamp' => $this->seconds($event->getTimeUnixNano()) ?? $start,
                    ...$this->exception($this->attributes($event->getAttributes())),
                ];
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function request(array $base, array $attributes, string $method, int $status): array
    {
        $url = $this->url($attributes);
        $path = parse_url($url, PHP_URL_PATH);

        return [
            ...$base,
            'type' => 'request',
            'method' => $method,
            // Without a route template, the path with its ids taken out, so its requests still group.
            'route' => $this->first($attributes, 'http.route') ?? ProcessIngestBatchAction::normalizePath(is_string($path) ? $path : '/'),
            'url' => $url,
            'domain' => $this->first($attributes, 'server.address', 'http.host'),
            'status' => $status,
        ];
    }

    /**
     * A consumer span is one job attempt: started at its start, finished at its end.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    private function job(Span $span, array $base, array $attributes): array
    {
        $job = [
            'type' => 'job',
            'trace_id' => $base['trace_id'],
            // Runs are keyed by uuid: the span's own ids, hashed into that shape.
            'uuid' => preg_replace('~^(.{8})(.{4})(.{4})(.{4})(.{12})$~', '$1-$2-$3-$4-$5', md5($base['trace_id'].$this->hex($span->getSpanId()))),
            'name' => $span->getName(),
            'queue' => $this->first($attributes, 'messaging.destination.name') ?? 'default',
            'connection' => $this->first($attributes, 'messaging.system'),
        ];
        $failed = $span->getStatus()?->getCode() === StatusCode::STATUS_CODE_ERROR;

        return [
            [...$job, 'status' => 'processing', 'timestamp' => $base['timestamp']],
            [...$job, 'status' => $failed ? 'failed' : 'processed', 'timestamp' => $this->seconds($span->getEndTimeUnixNano()), 'duration_ms' => $base['duration_ms']],
        ];
    }

    /**
     * A GenAI span as an AI call. Agent and tool spans wrap the model calls under them, whose
     * tokens they would count a second time, so they are skipped.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    private function aiCall(Span $span, array $base, array $attributes): array
    {
        $name = $this->first($attributes, 'gen_ai.operation.name');

        if (in_array($name, self::AI_WRAPPER_OPERATIONS, true)) {
            return [];
        }

        // Any other operation is a model call: one Lumberjack has no name for counts as text.
        $operation = $name === 'embeddings' ? AiOperation::Embeddings : AiOperation::Text;

        $error = $this->first($attributes, 'error.type');
        $failed = $error !== null || $span->getStatus()?->getCode() === StatusCode::STATUS_CODE_ERROR;
        $actorType = $this->first($attributes, 'lumberjack.ai.actor.type');
        $actorId = $this->first($attributes, 'lumberjack.ai.actor.id');
        $userId = $this->first($attributes, 'user.id');

        return [[
            ...$base,
            'type' => 'ai_call',
            'source' => AiCallSource::Otel->value,
            'provider' => $this->first($attributes, 'gen_ai.provider.name', 'gen_ai.system'),
            'model' => $this->first($attributes, 'gen_ai.response.model', 'gen_ai.request.model'),
            'operation' => $operation->value,
            'tokens' => [
                // The older names first came as prompt and completion tokens.
                'input' => $this->first($attributes, 'gen_ai.usage.input_tokens', 'gen_ai.usage.prompt_tokens'),
                'output' => $this->first($attributes, 'gen_ai.usage.output_tokens', 'gen_ai.usage.completion_tokens'),
                'cache_read' => $attributes['gen_ai.usage.cache_read.input_tokens'] ?? null,
                'cache_write' => $attributes['gen_ai.usage.cache_creation.input_tokens'] ?? null,
                'reasoning' => $attributes['gen_ai.usage.reasoning.output_tokens'] ?? null,
            ],
            'status' => ($failed ? AiCallStatus::Error : AiCallStatus::Ok)->value,
            'error' => $error,
            'key' => [
                'sha256' => $this->first($attributes, 'lumberjack.ai.key.sha256'),
                'label' => $this->first($attributes, 'lumberjack.ai.key.label'),
                'owner' => ['type' => $this->first($attributes, 'lumberjack.ai.key.owner.type'), 'id' => $this->first($attributes, 'lumberjack.ai.key.owner.id')],
            ],
            // The signed in user, when the app names no whole actor of its own.
            'actor' => $actorType !== null && $actorId !== null
                ? ['type' => $actorType, 'id' => $actorId]
                : ['type' => $userId !== null ? 'user' : null, 'id' => $userId],
        ]];
    }

    /**
     * The full URL, from the newer or older HTTP conventions, or put together from its parts.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function url(array $attributes): string
    {
        $full = $this->first($attributes, 'url.full', 'http.url');

        if ($full !== null) {
            return $full;
        }

        $host = $this->first($attributes, 'server.address', 'http.host');
        $port = $this->first($attributes, 'server.port');
        $query = $this->first($attributes, 'url.query');
        $origin = $host !== null ? ($this->first($attributes, 'url.scheme', 'http.scheme') ?? 'https').'://'.$host.($port !== null && ! in_array($port, ['80', '443'], true) ? ':'.$port : '') : '';

        return $origin.($this->first($attributes, 'url.path', 'http.target') ?? '/').($query !== null ? '?'.$query : '');
    }

    /**
     * An outgoing call's host and path, as the trace view shows it.
     */
    private function target(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        return (is_string($host) ? $host : '').(is_string($path) ? $path : '');
    }
}
