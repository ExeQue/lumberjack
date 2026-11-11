<?php

declare(strict_types=1);

namespace App\Domains\OpenTelemetry\Http\Requests;

use App\Domains\Ingest\Http\Requests\IngestBatchRequest;
use App\Domains\Projects\Models\Project;
use Google\Protobuf\Internal\Message;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use stdClass;
use Throwable;

/**
 * An OTLP/HTTP export: protobuf or JSON, plain or gzip, answered in the encoding it came in.
 * See https://opentelemetry.io/docs/specs/otlp/#otlphttp.
 */
class OtlpExportRequest extends FormRequest
{
    /** The largest body after gzip, as for an agent's batch. */
    public const int MAX_INFLATED_BYTES = IngestBatchRequest::MAX_BYTES * 20;

    /** OTLP/JSON writes these ids as hex; the protobuf JSON parser reads bytes as base64. */
    private const array ID_FIELDS = ['traceId', 'spanId', 'parentSpanId', 'trace_id', 'span_id', 'parent_span_id'];

    public function authorize(): bool
    {
        return $this->attributes->get('project') instanceof Project;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->attributes->get('project');
    }

    /**
     * Reads the body into the given export request; a body that is not one is answered with 400.
     *
     * @template T of Message
     *
     * @param  T  $message
     * @return T
     */
    public function decodeInto(Message $message): Message
    {
        if (! $this->isProtobuf() && ! $this->isJson()) {
            throw $this->failure(415, __('opentelemetry.errors.content_type'));
        }

        $body = $this->body();

        try {
            if ($this->isProtobuf()) {
                $message->mergeFromString($body);
            } else {
                $json = json_decode($body, false, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);

                if (! $json instanceof stdClass) {
                    throw $this->failure(400, __('opentelemetry.errors.undecodable'));
                }

                $message->mergeFromJsonString((string) json_encode(self::binaryIds($json)), true);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable) {
            throw $this->failure(400, __('opentelemetry.errors.undecodable'));
        }

        return $message;
    }

    /**
     * The export's answer, in the encoding of the request.
     */
    public function answer(Message $response): Response
    {
        return $this->isProtobuf()
            ? response($response->serializeToString(), 200, ['Content-Type' => 'application/x-protobuf'])
            : response($response->serializeToJsonString(), 200, ['Content-Type' => 'application/json']);
    }

    public function isProtobuf(): bool
    {
        return str_contains(strtolower((string) $this->header('Content-Type')), 'application/x-protobuf');
    }

    private function body(): string
    {
        $raw = $this->getContent();

        if (strlen($raw) > IngestBatchRequest::MAX_BYTES) {
            throw $this->failure(413, __('opentelemetry.errors.too_large'));
        }

        if (! str_contains(strtolower((string) $this->header('Content-Encoding')), 'gzip')) {
            return $raw;
        }

        // gzip ends with the inflated size, so a body too large is turned away before inflating it.
        // A body that lies about it still stops at the cap, as broken.
        if (str_starts_with($raw, "\x1F\x8B") && (unpack('V', substr($raw, -4)) ?: [1 => 0])[1] > self::MAX_INFLATED_BYTES) {
            throw $this->failure(413, __('opentelemetry.errors.too_large'));
        }

        $inflated = @gzdecode($raw, self::MAX_INFLATED_BYTES);

        if ($inflated === false) {
            throw $this->failure(400, __('opentelemetry.errors.gzip'));
        }

        return $inflated;
    }

    /**
     * An error answer as OTLP/HTTP asks: a google.rpc.Status with only its message, in the
     * request's encoding. In protobuf that is field 2, a length-prefixed string.
     */
    private function failure(int $status, string $message): HttpResponseException
    {
        if (! $this->isProtobuf()) {
            return new HttpResponseException(response()->json(['message' => $message], $status));
        }

        $length = '';

        for ($n = strlen($message); $n > 0x7F; $n >>= 7) {
            $length .= pack('C', ($n & 0x7F) | 0x80);
        }

        return new HttpResponseException(response("\x12".$length.pack('C', $n).$message, $status, ['Content-Type' => 'application/x-protobuf']));
    }

    private static function binaryIds(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::binaryIds(...), $value);
        }

        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = in_array($key, self::ID_FIELDS, true) && is_string($item) && $item !== '' && strlen($item) % 2 === 0 && ctype_xdigit($item)
                    ? base64_encode((string) hex2bin($item))
                    : self::binaryIds($item);
            }
        }

        return $value;
    }
}
