<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use App\Domains\Health\Exceptions\PrivateAddressException;
use App\Domains\Health\Exceptions\UnresolvableHostException;

/**
 * Keeps checks off this server and the network behind it: a host is resolved once,
 * every address it has must be public, and the check connects to that address, so a
 * second lookup cannot point it somewhere else. LUMBERJACK_HEALTH_ALLOW_PRIVATE lets
 * self-hosters check internal services.
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
     * The address to connect to.
     */
    public function resolve(string $host): string
    {
        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->dns->addresses($host);

        if ($addresses === []) {
            throw UnresolvableHostException::forHost($host);
        }

        if (! (bool) config('health.allow_private', false)) {
            foreach ($addresses as $address) {
                if (! self::isPublic($address)) {
                    throw PrivateAddressException::forHost($host);
                }
            }
        }

        return $addresses[0];
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

        // IPv6: unique local fc00::/7, link-local fe80::/10, multicast ff00::/8, documentation 2001:db8::/32.
        $bytes = (string) inet_pton($address);

        return ! ((ord($bytes[0]) & 0xFE) === 0xFC
            || (ord($bytes[0]) === 0xFE && (ord($bytes[1]) & 0xC0) === 0x80)
            || ord($bytes[0]) === 0xFF
            || str_starts_with(bin2hex($bytes), '20010db8'));
    }
}
