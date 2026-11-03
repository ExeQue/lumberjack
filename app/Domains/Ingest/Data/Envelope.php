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
     * The domain a request was made to, lowercased: as the event says, or else the host
     * (and port, when the URL has one) of its URL.
     */
    public static function domain(mixed $domain, mixed $url = null): ?string
    {
        if (self::short($domain, 255) === null && is_string($url)) {
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);
            $domain = is_string($host) ? $host.(is_int($port) ? ':'.$port : '') : null;
        }

        $domain = self::short($domain, 255);

        return $domain !== null ? mb_strtolower($domain) : null;
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

    /**
     * How a run, or a query or span of one, was picked: the rate that applied, and whether
     * it was sampled (false: kept only because it was slow or failed). An SDK that does not
     * sample sends neither, which is a rate of 1.
     *
     * @param  array<string, mixed>  $event
     * @return array{sample_rate: float, sampled: bool}
     */
    public static function sampling(array $event): array
    {
        $rate = isset($event['sample_rate']) && is_numeric($event['sample_rate']) ? max(0.0, min(1.0, (float) $event['sample_rate'])) : 1.0;

        return ['sample_rate' => $rate, 'sampled' => filter_var($event['sampled'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true];
    }
}
