<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

/**
 * DNS lookups, behind a class so tests can answer them.
 */
class DnsResolver
{
    /**
     * The IPv4 and IPv6 addresses a host name points at.
     *
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $v6 = array_map(fn (array $r): string => (string) $r['ipv6'], @dns_get_record($host, DNS_AAAA) ?: []);

        return array_values(array_unique([...$v4, ...$v6]));
    }

    /**
     * The values of one record type: addresses, a CNAME target, MX hosts or TXT text.
     *
     * @return list<string>
     */
    public function records(string $name, string $type): array
    {
        $records = @dns_get_record($name, match ($type) {
            'AAAA' => DNS_AAAA,
            'CNAME' => DNS_CNAME,
            'MX' => DNS_MX,
            'TXT' => DNS_TXT,
            default => DNS_A,
        }) ?: [];

        return array_map(fn (array $r): string => (string) ($r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? $r['txt'] ?? ''), $records);
    }
}
