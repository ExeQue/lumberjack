<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Data;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The batch-level fields every event inherits.
 */
final readonly class Envelope
{
    public function __construct(
        public ?string $environment,
        public ?string $release,
        public ?string $host,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            self::short($payload['environment'] ?? null),
            self::short($payload['release'] ?? null),
            isset($payload['host']) && is_string($payload['host']) ? mb_substr($payload['host'], 0, 255) : null,
        );
    }

    public static function short(mixed $value, int $max = 64): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, $max) : null;
    }

    /**
     * Unix seconds (float, milliseconds as fraction) or an ISO 8601 string.
     */
    public static function time(mixed $value): CarbonImmutable
    {
        if (is_int($value) || is_float($value)) {
            return CarbonImmutable::createFromTimestampMs((int) round($value * 1000));
        }

        if (is_string($value) && $value !== '') {
            try {
                // Stored as the app's own timezone, whatever offset the SDK sent.
                return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
            } catch (Throwable) {
                return CarbonImmutable::now();
            }
        }

        return CarbonImmutable::now();
    }

    public static function micros(mixed $milliseconds): int
    {
        return is_numeric($milliseconds) ? max(0, min(4_294_967_295, (int) round((float) $milliseconds * 1000))) : 0;
    }
}
