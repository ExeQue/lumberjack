<?php

declare(strict_types=1);

namespace App\Domains\Shared\Support;

use Illuminate\Support\Str;

/**
 * Removes secrets from context arrays. The SDKs scrub too; this is the second line.
 * A project can add its own keys ("Extra secret fields") on top of the defaults.
 */
class Scrubber
{
    public const string MASK = '[filtered]';

    /** @var list<string> */
    public const array SENSITIVE = ['password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'apikey', 'credit_card', 'card_number', 'cvv', 'session_id'];

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

        return $this->walk($data, $contains, $patterns, '', 0);
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
