<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Support;

/**
 * Signs a pull so the app can tell it is Lumberjack's: an HMAC with the pull token, which
 * itself never travels. The host is signed too, so a signature handed to another server
 * is worth nothing on the real one. The Laravel package repeats this format.
 */
final class PullSignature
{
    public const string VERSION = 'v1';

    /**
     * @param  array<string, mixed>  $query
     */
    public static function sign(string $token, string $method, string $host, string $path, array $query, int $timestamp): string
    {
        ksort($query);
        $canonical = implode("\n", [self::VERSION, strtoupper($method), strtolower($host), $path, http_build_query($query, '', '&', PHP_QUERY_RFC3986), $timestamp]);

        return self::VERSION.'='.hash_hmac('sha256', $canonical, $token);
    }
}
