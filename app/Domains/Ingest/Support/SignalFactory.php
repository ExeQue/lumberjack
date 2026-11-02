<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Support;

use App\Domains\Ingest\Data\Envelope;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Shared\Support\Scrubber;

/**
 * Turns an exception event from an SDK into an issue signal.
 */
class SignalFactory
{
    public const int MAX_FRAMES = 100;

    public function __construct(
        private readonly Scrubber $scrubber,
    ) {
    }

    /**
     * @param  array<string, mixed>  $event
     */
    /**
     * @param  array<string, mixed>  $event
     * @param  list<string>  $scrubKeys
     */
    public function fromException(array $event, Envelope $envelope, IssueSource $source, array $scrubKeys = []): IssueSignal
    {
        $frames = array_values(array_filter(is_array($event['stacktrace'] ?? null) ? $event['stacktrace'] : [], 'is_array'));
        $frames = array_map(fn (array $frame): array => $this->frame($frame), array_slice($frames, 0, self::MAX_FRAMES));

        $context = is_array($event['context'] ?? null) ? $this->scrubber->scrub($event['context'], $scrubKeys) : [];

        return new IssueSignal(
            source: $source,
            title: (string) ($event['class'] ?? 'Error'),
            message: (string) ($event['message'] ?? ''),
            file: Envelope::short($event['file'] ?? null, 255),
            line: isset($event['line']) && is_numeric($event['line']) ? (int) $event['line'] : null,
            stacktrace: $frames,
            context: $context,
            occurredAt: Envelope::time($event['timestamp'] ?? null),
            traceId: Envelope::short($event['trace_id'] ?? null),
            environment: $envelope->environment,
            release: $envelope->release,
            host: $envelope->host,
            customFingerprint: Envelope::short($event['fingerprint'] ?? null, 255),
        );
    }

    /**
     * @param  array<string, mixed>  $frame
     * @return array<string, mixed>
     */
    private function frame(array $frame): array
    {
        $code = is_array($frame['code'] ?? null) ? $frame['code'] : null;

        return array_filter([
            'file' => Envelope::short($frame['file'] ?? null, 500),
            'line' => isset($frame['line']) && is_numeric($frame['line']) ? (int) $frame['line'] : null,
            'column' => isset($frame['column']) && is_numeric($frame['column']) ? (int) $frame['column'] : null,
            'function' => Envelope::short($frame['function'] ?? null, 255),
            'class' => Envelope::short($frame['class'] ?? null, 255),
            'in_app' => (bool) ($frame['in_app'] ?? false),
            'code' => $code !== null ? [
                'start' => (int) ($code['start'] ?? 1),
                'lines' => array_map(fn ($l): string => mb_substr((string) $l, 0, 500), array_slice(array_values((array) ($code['lines'] ?? [])), 0, 15)),
            ] : null,
        ], fn ($v): bool => $v !== null);
    }
}
