<?php

declare(strict_types=1);

namespace App\Domains\OpenTelemetry\Actions\Concerns;

use App\Domains\Ingest\Http\Requests\IngestBatchRequest;
use App\Domains\Ingest\Jobs\ProcessIngestBatch;
use App\Domains\OpenTelemetry\Exceptions\TooManyEventsException;
use App\Domains\Projects\Models\Project;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Resource\V1\Resource;

/**
 * Turns OTLP values into the event arrays of an ingest batch, and hands the batches
 * to the same job an agent's batch goes through. The using Action injects a StackTraceParser
 * as $stackTraceParser.
 */
trait MapsOtlpData
{
    /** The longest raw stack trace kept when no frames could be read from it. */
    private const int MAX_RAW_TRACE = 20_000;

    /**
     * @param  iterable<KeyValue>  $attributes
     * @return array<string, mixed>
     */
    private function attributes(iterable $attributes): array
    {
        $values = [];

        foreach ($attributes as $attribute) {
            $values[$attribute->getKey()] = $this->value($attribute->getValue());
        }

        return $values;
    }

    private function value(?AnyValue $value): mixed
    {
        return match ($value?->getValue()) {
            'string_value' => $value->getStringValue(),
            'bool_value' => $value->getBoolValue(),
            'int_value' => (int) $value->getIntValue(),
            'double_value' => $value->getDoubleValue(),
            'bytes_value' => bin2hex($value->getBytesValue()),
            'array_value' => array_map(fn (AnyValue $item): mixed => $this->value($item), iterator_to_array($value->getArrayValue()?->getValues() ?? [], false)),
            'kvlist_value' => $this->attributes($value->getKvlistValue()?->getValues() ?? []),
            default => null,
        };
    }

    /**
     * The first of the keys that holds a value, as a string.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function first(array $attributes, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (is_scalar($attributes[$key] ?? null) && (string) $attributes[$key] !== '') {
                return (string) $attributes[$key];
            }
        }

        return null;
    }

    /**
     * The batch fields a resource sets for every event of it.
     *
     * @return array{environment: ?string, release: ?string, host: ?string}
     */
    private function envelope(?Resource $resource): array
    {
        $attributes = $this->attributes($resource?->getAttributes() ?? []);

        return [
            'environment' => $this->first($attributes, 'deployment.environment.name', 'deployment.environment'),
            'release' => $this->first($attributes, 'service.version'),
            'host' => $this->first($attributes, 'host.name'),
        ];
    }

    /**
     * Unix nanoseconds as the Unix seconds an ingest event carries; null when unset.
     */
    private function seconds(int|string $nanos): ?float
    {
        return (int) $nanos > 0 ? (int) $nanos / 1e9 : null;
    }

    private function hex(string $id): ?string
    {
        return $id !== '' ? bin2hex($id) : null;
    }

    /**
     * An exception event's fields from the `exception.*` attributes of a span event or log record.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function exception(array $attributes): array
    {
        $trace = $this->first($attributes, 'exception.stacktrace') ?? '';
        $frames = $this->stackTraceParser->parse($trace);

        return array_filter([
            'class' => $this->first($attributes, 'exception.type') ?? 'Error',
            'message' => $this->first($attributes, 'exception.message') ?? '',
            'file' => $frames[0]['file'] ?? null,
            'line' => $frames[0]['line'] ?? null,
            'stacktrace' => $frames,
            // A trace in a format the parser does not know is still shown, as text.
            'context' => $frames === [] && $trace !== '' ? ['stacktrace' => mb_substr($trace, 0, self::MAX_RAW_TRACE)] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * One ingest batch per environment, release and host, as an agent would send them. An
     * export holds no more events than one agent batch may, as throttling counts requests.
     *
     * @param  list<array{array{environment: ?string, release: ?string, host: ?string}, list<array<string, mixed>>}>  $parts
     *
     * @throws TooManyEventsException
     */
    private function dispatch(Project $project, array $parts): void
    {
        if (array_sum(array_map(fn (array $part): int => count($part[1]), $parts)) > IngestBatchRequest::MAX_EVENTS) {
            throw new TooManyEventsException(__('opentelemetry.errors.too_many_events', ['max' => IngestBatchRequest::MAX_EVENTS]));
        }

        $batches = [];

        foreach ($parts as [$envelope, $events]) {
            $key = (string) json_encode($envelope);
            $batches[$key] ??= ['envelope' => $envelope, 'events' => []];
            array_push($batches[$key]['events'], ...$events);
        }

        foreach ($batches as $batch) {
            foreach (array_chunk($batch['events'], IngestBatchRequest::MAX_EVENTS) as $events) {
                ProcessIngestBatch::dispatch($project->id, ['v' => 1, ...$batch['envelope'], 'events' => $events]);
            }
        }
    }
}
