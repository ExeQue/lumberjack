<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

use App\Domains\Health\Exceptions\ProbeFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * When a domain's registration runs out: from the registry's RDAP service, or from
 * whois where the top-level domain has none (.dk, .io, .se). Registries limit how often
 * they are asked and the date rarely moves, so an answer is kept for a few hours.
 */
class DomainExpiryLookup
{
    private const string RDAP_BOOTSTRAP = 'https://data.iana.org/rdap/dns.json';

    private const string WHOIS_ROOT = 'whois.iana.org';

    private const int EXPIRY_CACHE_SECONDS = 21_600;

    private const int DIRECTORY_CACHE_SECONDS = 86_400;

    private const int MAX_BODY_BYTES = 1_048_576;

    private const float TIMEOUT_S = 10.0;

    private const string WHOIS_EXPIRY = '/^\s*(?:registry expiry date|registrar registration expiration date|expiration date|expiry date|expire date|expires on|expires|expire|paid-till|renewal date|valid until)\s*[:.]+\s*(\S.*?)\s*$/im';

    public function __construct(
        private readonly NetworkGuard $guard,
        private readonly WhoisClient $whois,
    ) {
    }

    public function expiresAt(string $domain): CarbonImmutable
    {
        $domain = strtolower($domain);
        $date = Cache::remember('health:domain-expiry:'.$domain, self::EXPIRY_CACHE_SECONDS, function () use ($domain): string {
            $tld = substr((string) strrchr($domain, '.'), 1);
            $rdap = $this->rdapServices()[$tld] ?? null;

            return $rdap !== null ? $this->fromRdap($rdap, $domain) : $this->fromWhois($tld, $domain);
        });

        try {
            return CarbonImmutable::parse((string) $date);
        } catch (Throwable) {
            throw new ProbeFailedException(__('health.results.domain_no_expiry', ['domain' => $domain]));
        }
    }

    /**
     * @return array<string, string> the RDAP base URL of each top-level domain that has one
     */
    private function rdapServices(): array
    {
        return Cache::remember('health:rdap-services', self::DIRECTORY_CACHE_SECONDS, function (): array {
            $services = [];

            foreach ((array) $this->get(self::RDAP_BOOTSTRAP)->throw()->json('services', []) as $service) {
                foreach ((array) ($service[0] ?? []) as $tld) {
                    $services[(string) $tld] = (string) ($service[1][0] ?? '');
                }
            }

            return array_filter($services);
        });
    }

    private function fromRdap(string $base, string $domain): string
    {
        $response = $this->get(rtrim($base, '/').'/domain/'.$domain);

        if ($response->status() === 404) {
            throw new ProbeFailedException(__('health.results.domain_not_found', ['domain' => $domain]));
        }

        foreach ((array) $response->json('events', []) as $event) {
            if (($event['eventAction'] ?? null) === 'expiration' && is_string($event['eventDate'] ?? null)) {
                return $event['eventDate'];
            }
        }

        throw new ProbeFailedException(__('health.results.domain_no_expiry', ['domain' => $domain]));
    }

    private function fromWhois(string $tld, string $domain): string
    {
        $server = Cache::remember('health:whois-server:'.$tld, self::DIRECTORY_CACHE_SECONDS, function () use ($tld): ?string {
            $answer = $this->ask(self::WHOIS_ROOT, $tld);

            return preg_match('/^whois:\s*(\S+)/im', $answer, $found) === 1 ? $found[1] : null;
        });

        if ($server === null || preg_match(self::WHOIS_EXPIRY, $this->ask($server, $domain), $found) !== 1) {
            throw new ProbeFailedException(__('health.results.domain_no_expiry', ['domain' => $domain]));
        }

        return $found[1];
    }

    private function ask(string $server, string $query): string
    {
        return $this->whois->query($this->guard->resolve($server, allowPrivate: false), $query, self::TIMEOUT_S);
    }

    /**
     * @throws ConnectionException
     */
    private function get(string $url): Response
    {
        return $this->guard->send(
            Http::withoutRedirecting()->accept('application/rdap+json, application/json')->timeout((int) self::TIMEOUT_S)->connectTimeout(5)->withUserAgent('Lumberjack-Health/1'),
            'GET',
            $url,
            self::MAX_BODY_BYTES,
        );
    }
}
