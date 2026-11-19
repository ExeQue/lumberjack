<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

/**
 * When an AI error rate rule tells about a key: more than $rate percent of its calls in
 * the last $minutes failed, out of at least $minCalls calls, so a single failure on a
 * quiet key says nothing.
 */
final readonly class AiErrorRateSettings
{
    public const int DEFAULT_MINUTES = 15;

    public const int DEFAULT_RATE = 50;

    public const int DEFAULT_MIN_CALLS = 10;

    public function __construct(
        public int $minutes = self::DEFAULT_MINUTES,
        public int $rate = self::DEFAULT_RATE,
        public int $minCalls = self::DEFAULT_MIN_CALLS,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $settings  as stored on the rule
     */
    public static function fromArray(?array $settings): self
    {
        $int = fn (string $key, int $default): int => is_numeric($settings[$key] ?? null) ? (int) $settings[$key] : $default;

        return new self($int('minutes', self::DEFAULT_MINUTES), $int('rate', self::DEFAULT_RATE), $int('min_calls', self::DEFAULT_MIN_CALLS));
    }

    /**
     * @return array{minutes: int, rate: int, min_calls: int}
     */
    public function toArray(): array
    {
        return ['minutes' => $this->minutes, 'rate' => $this->rate, 'min_calls' => $this->minCalls];
    }

    public function breached(int $calls, int $errors): bool
    {
        return $calls >= $this->minCalls && $errors * 100 > $this->rate * $calls;
    }

    /**
     * "Over 50% of at least 10 calls failed in 15 minutes".
     */
    public function describe(): string
    {
        return (string) __('alerts.ai_error_rate.threshold', ['rate' => $this->rate, 'calls' => $this->minCalls, 'minutes' => $this->minutes]);
    }
}
