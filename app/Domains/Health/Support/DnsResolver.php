<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use App\Domains\Health\Exceptions\ProbeFailedException;
use NetDNS2\ENUM\Error;
use NetDNS2\Exception as DnsException;
use NetDNS2\Resolver;
use NetDNS2\RR\A;
use NetDNS2\RR\AAAA;
use NetDNS2\RR\CNAME;
use NetDNS2\RR\MX;
use NetDNS2\RR\PTR;
use NetDNS2\RR\TXT;

/**
 * DNS lookups, behind a class so tests can answer them.
 */
class DnsResolver
{
    /** How long one name server gets to answer. */
    private const float TIMEOUT_S = 3.0;

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
     * The values of one record type: addresses, a CNAME or PTR target, MX hosts or TXT
     * text. A name that does not exist has none; a lookup that got no answer throws, so
     * a resolver that is down is never read as a record that is missing.
     *
     * Asked over the DNS library and not dns_get_record(), which cannot tell the two
     * apart, has no timeout, and fails on answers too large for UDP wherever the resolver
     * does not take TCP. It reads neither /etc/hosts nor the search domains.
     *
     * @return list<string>
     *
     * @throws ProbeFailedException
     */
    public function records(string $name, string $type): array
    {
        $type = in_array($type, ['AAAA', 'CNAME', 'MX', 'PTR', 'TXT'], true) ? $type : 'A';
        $servers = (array) config('health.dns_servers', []);

        try {
            $answers = new Resolver(['timeout' => self::TIMEOUT_S, ...($servers === [] ? [] : ['nameservers' => $servers])])->query($name, $type)->answer;
        } catch (DnsException $e) {
            if ($e->getCode() === Error::DNS_NXDOMAIN->value) {
                return [];
            }

            throw new ProbeFailedException(__('health.results.dns_failed', ['type' => $type, 'name' => $name, 'error' => $e->getMessage()]), previous: $e);
        }

        $records = [];

        // An answer through a CNAME carries the CNAME too; only records of the type asked for count.
        foreach ($answers as $answer) {
            $value = match (true) {
                $answer instanceof A && $type === 'A', $answer instanceof AAAA && $type === 'AAAA' => (string) $answer->address,
                $answer instanceof CNAME && $type === 'CNAME' => (string) $answer->cname,
                $answer instanceof MX && $type === 'MX' => (string) $answer->exchange,
                $answer instanceof PTR && $type === 'PTR' => (string) $answer->ptrdname,
                $answer instanceof TXT && $type === 'TXT' => implode('', array_map(strval(...), $answer->text)),
                default => null,
            };

            if ($value !== null) {
                $records[] = $value;
            }
        }

        return $records;
    }
}
