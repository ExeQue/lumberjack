<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use IPLib\Address\AddressInterface;
use IPLib\Factory;
use Override;
use SPFLib\DNS\Resolver;

/**
 * Lets the SPF library look up DNS through the resolver the checks use, so tests answer
 * its lookups too.
 */
final readonly class SpfDnsResolver implements Resolver
{
    public function __construct(private DnsResolver $dns)
    {
    }

    #[Override]
    public function getTXTRecords(string $domain): array
    {
        return $this->dns->records($domain, 'TXT');
    }

    #[Override]
    public function getIPAddressesFromDomainName(string $domain): array
    {
        return array_values(array_filter(array_map(Factory::parseAddressString(...), $this->dns->addresses($domain))));
    }

    #[Override]
    public function getMXRecords(string $domain): array
    {
        return $this->dns->records($domain, 'MX');
    }

    #[Override]
    public function getPTRRecords(string $domain): array
    {
        return $this->dns->records($domain, 'PTR');
    }

    #[Override]
    public function getDomainNameFromIPAddress(AddressInterface $ip): string
    {
        return $this->dns->records($ip->getReverseDNSLookupName(), 'PTR')[0] ?? '';
    }
}
