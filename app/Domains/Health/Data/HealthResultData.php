<?php

declare(strict_types=1);

namespace App\Domains\Health\Data;

use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Ingest\Data\Envelope;
use Carbon\CarbonImmutable;

/**
 * One result of a check, from the SDK or from a check Lumberjack ran.
 */
final readonly class HealthResultData
{
    public const int MAX_METRICS = 20;

    public const int MAX_MESSAGE = 1000;

    /**
     * @param  array<string, int|float>  $metrics
     */
    public function __construct(
        public HealthResultStatus $status,
        public ?string $message = null,
        public array $metrics = [],
        public ?int $durationUs = null,
        public string $host = '',
        public ?CarbonImmutable $at = null,
    ) {
    }

    /**
     * Anything the SDK sends that does not fit is clamped or dropped; an unknown status
     * means there is nothing to record.
     *
     * @param  array<string, mixed>  $event
     */
    public static function fromEvent(array $event, Envelope $envelope): ?self
    {
        $status = is_string($event['status'] ?? null) ? HealthResultStatus::tryFrom($event['status']) : null;

        if ($status === null) {
            return null;
        }

        $metrics = [];

        foreach (is_array($event['metrics'] ?? null) ? $event['metrics'] : [] as $name => $value) {
            if (count($metrics) >= self::MAX_METRICS) {
                break;
            }

            if (is_string($name) && $name !== '' && (is_int($value) || is_float($value)) && is_finite((float) $value)) {
                $metrics[mb_substr($name, 0, 64)] = $value;
            }
        }

        return new self(
            $status,
            self::message($event['message'] ?? null),
            $metrics,
            isset($event['duration_ms']) && is_numeric($event['duration_ms']) ? Envelope::micros($event['duration_ms']) : null,
            $envelope->host ?? '',
            Envelope::time($event['timestamp'] ?? null),
        );
    }

    public static function message(mixed $message): ?string
    {
        return is_string($message) && trim($message) !== '' ? mb_substr(trim($message), 0, self::MAX_MESSAGE) : null;
    }
}
