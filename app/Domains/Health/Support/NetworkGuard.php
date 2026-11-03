<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use App\Domains\Health\Exceptions\PrivateAddressException;
use App\Domains\Health\Exceptions\ProbeFailedException;
use App\Domains\Health\Exceptions\UnresolvableHostException;

/**
 * Keeps outbound requests (health checks, alert webhooks) off this server and the
 * network behind it: a host is resolved once, every address it has must be public, and
 * the request connects to that address, so a second lookup cannot point it somewhere
 * else. LUMBERJACK_HEALTH_ALLOW_PRIVATE lets self-hosters check internal services,
 * ALERTS_WEBHOOKS_ALLOW_PRIVATE lets alerts reach them.
 */
class NetworkGuard
{
    /**
     * Ranges PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE leave open.
     */
    private const array EXTRA_BLOCKED_V4 = [
        '100.64.0.0/10', // carrier-grade NAT
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4', // multicast
    ];

    public function __construct(
        private readonly DnsResolver $dns,
    ) {
    }

    /**
     * The address to connect to. Private addresses pass only when $allowPrivate is on;
     * null takes health.allow_private.
     */
    public function resolve(string $host, ?bool $allowPrivate = null): string
    {
        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->dns->addresses($host);

        if ($addresses === []) {
            throw UnresolvableHostException::forHost($host);
        }

        if (! ($allowPrivate ?? (bool) config('health.allow_private', false))) {
            foreach ($addresses as $address) {
                if (! self::isPublic($address)) {
                    throw PrivateAddressException::forHost($host);
                }
            }
        }

        return $addresses[0];
    }

    /**
     * Guzzle options that make an http(s) request to $url connect to the address this
     * guard vetted, whatever a later lookup of its host says.
     *
     * @return array<string, mixed>
     */
    public function pin(string $url, ?bool $allowPrivate = null): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ProbeFailedException(__('health.results.bad_url'));
        }

        $address = $this->resolve($host, $allowPrivate);

        // An address in the URL is connected to as it is; there is no lookup to pin.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return ['curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)]]];
    }

    public static function isPublic(string $address): bool
    {
        // ::ffff:127.0.0.1 is 127.0.0.1.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $m) === 1) {
            $address = $m[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::EXTRA_BLOCKED_V4 as $range) {
                [$net, $bits] = explode('/', $range);
                $mask = -1 << (32 - (int) $bits);

                if ((ip2long($address) & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        $bytes = (string) inet_pton($address);
        $hex = bin2hex($bytes);

        // IPv6 that carries an IPv4 address: NAT64 64:ff9b::/96 and 6to4 2002::/16 reach it.
        if (str_starts_with($hex, '0064ff9b0000000000000000')) {
            return self::isPublic((string) inet_ntop(substr($bytes, 12, 4)));
        }

        if (str_starts_with($hex, '2002')) {
            return self::isPublic((string) inet_ntop(substr($bytes, 2, 4)));
        }

        // Local-use NAT64 64:ff9b:1::/48, Teredo 2001::/32, IPv4-compatible ::/96, discard 100::/64.
        if (str_starts_with($hex, '0064ff9b0001') || str_starts_with($hex, '20010000')
            || str_starts_with($hex, '000000000000000000000000') || str_starts_with($hex, '0100000000000000')) {
            return false;
        }

        // Unique local fc00::/7, link-local fe80::/10, multicast ff00::/8, documentation 2001:db8::/32.
        return ! ((ord($bytes[0]) & 0xFE) === 0xFC
            || (ord($bytes[0]) === 0xFE && (ord($bytes[1]) & 0xC0) === 0x80)
            || ord($bytes[0]) === 0xFF
            || str_starts_with($hex, '20010db8'));
    }
}
