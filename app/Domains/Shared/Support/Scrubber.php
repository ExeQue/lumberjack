<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use Illuminate\Support\Str;

/**
 * Removes secrets from context arrays. The SDKs scrub too; this is the second line.
 * A project can add its own keys ("Extra secret fields") on top of the defaults. Values
 * are masked too where they look like a secret (VALUE_PATTERNS, plus
 * lumberjack.scrubbing.value_patterns), and a context larger than MAX_BYTES is cut down.
 */
class Scrubber
{
    public const string MASK = '[filtered]';

    /** The most JSON one context keeps; top-level entries past it are replaced by a marker. */
    public const int MAX_BYTES = 65_536;

    public const string TRUNCATED = '[truncated]';

    /** @var list<string> */
    public const array SENSITIVE = ['password', 'passwd', 'pwd', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'apikey', 'access_key', 'private_key', 'jwt', 'dsn', 'credit_card', 'card_number', 'cvv', 'session_id'];

    /**
     * Secrets recognized by their value, pattern => replacement.
     *
     * @var array<string, string>
     */
    public const array VALUE_PATTERNS = [
        // Authorization schemes: Bearer eyJ..., Basic dXNlcjpwYXNz
        '/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 '.self::MASK,
        // Query and form parameters: ?token=..., &X-Amz-Signature=..., password=...
        '/((?:^|[?&;\s])(?:[\w.-]*[_.-])?(?:password|passwd|pwd|token|secret|signature|sig|key|apikey|auth|jwt)=)[^&#\s]+/i' => '$1'.self::MASK,
        // Credentials in a URL: scheme://user:password@host
        '#(\b[a-z][a-z0-9+.-]*://[^:/\s@]+:)[^@/\s]+@#i' => '$1'.self::MASK.'@',
        // JSON Web Tokens
        '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/' => self::MASK,
        // AWS access key ids
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => self::MASK,
        // Stripe keys and webhook secrets
        '/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{10,}|\bwhsec_[A-Za-z0-9]{10,}/' => self::MASK,
        // Lumberjack's own status screen share keys (/s/ls_...)
        '/\bls_[a-z0-9]{40}\b/' => self::MASK,
        // GitHub and Slack tokens
        '/\bgh[pousr]_[A-Za-z0-9]{30,}|\bxox[abposr]-[A-Za-z0-9-]{10,}/' => self::MASK,
    ];

    /** @var array<string, string> */
    private readonly array $valuePatterns;

    /**
     * @param  array<string, string>  $extraValuePatterns  lumberjack.scrubbing.value_patterns
     */
    public function __construct(array $extraValuePatterns = [])
    {
        $this->valuePatterns = [...self::VALUE_PATTERNS, ...$extraValuePatterns];
    }

    /**
     * A single value, such as a request URL, with what looks like a secret masked.
     */
    public function scrubString(string $value): string
    {
        return (string) (preg_replace(array_keys($this->valuePatterns), array_values($this->valuePatterns), $value) ?? $value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $extraKeys  the project's own secret fields:
     *                                   "cpr" masks any key containing cpr,
     *                                   "card_*" masks keys matching the pattern,
     *                                   "session.checkout.*" matches the full dotted path
     * @return array<array-key, mixed>
     */
    public function scrub(array $data, array $extraKeys = []): array
    {
        $contains = self::SENSITIVE;
        $patterns = [];

        foreach ($extraKeys as $key) {
            $key = $this->normalize($key);

            if ($key === '') {
                continue;
            }

            if (str_contains($key, '*') || str_contains($key, '.')) {
                $patterns[] = $key;
            } else {
                $contains[] = $key;
            }
        }

        return $this->cap($this->walk($data, $contains, $patterns, '', 0));
    }

    /**
     * Keeps whole top-level entries while they fit in MAX_BYTES; the rest become a marker.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function cap(array $data): array
    {
        if (strlen((string) json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR)) <= self::MAX_BYTES) {
            return $data;
        }

        $used = 0;

        foreach ($data as $key => $value) {
            $size = strlen((string) json_encode([$key => $value], JSON_PARTIAL_OUTPUT_ON_ERROR));

            if ($used + $size > self::MAX_BYTES) {
                $data[$key] = self::TRUNCATED.' '.(int) ceil($size / 1024).' KB';

                continue;
            }

            $used += $size;
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $contains
     * @param  list<string>  $patterns
     * @return array<array-key, mixed>
     */
    private function walk(array $data, array $contains, array $patterns, string $path, int $depth): array
    {
        if ($depth > 8) {
            return [self::MASK];
        }

        foreach ($data as $key => $value) {
            $name = $this->normalize((string) $key);
            $fullPath = $path === '' ? $name : $path.'.'.$name;

            if ($this->isSensitive($name, $fullPath, $contains, $patterns)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->walk($value, $contains, $patterns, $fullPath, $depth + 1);
            } elseif (is_string($value)) {
                $data[$key] = $this->scrubString($value);
            }
        }

        return $data;
    }

    /**
     * @param  list<string>  $contains
     * @param  list<string>  $patterns
     */
    private function isSensitive(string $name, string $fullPath, array $contains, array $patterns): bool
    {
        foreach ($contains as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        foreach ($patterns as $pattern) {
            if (Str::is($pattern, str_contains($pattern, '.') ? $fullPath : $name)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $key): string
    {
        return strtolower(str_replace('-', '_', trim($key)));
    }
}
