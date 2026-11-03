<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use App\Domains\Health\Exceptions\ProbeFailedException;
use App\Domains\Health\Support\DnsResolver;
use App\Domains\Health\Support\TcpProbe;
use App\Domains\Health\Support\TlsCertificateReader;
use Carbon\CarbonImmutable;

trait FakesHealthProbes
{
    /**
     * Every host resolves to these addresses; DNS checks find these records.
     *
     * @param  list<string>  $addresses
     * @param  list<string>  $records
     */
    public function fakeDns(array $addresses = ['93.184.215.14'], array $records = []): void
    {
        $this->app->instance(DnsResolver::class, new class ($addresses, $records) extends DnsResolver {
            /**
             * @param  list<string>  $addresses
             * @param  list<string>  $records
             */
            public function __construct(private readonly array $addresses, private readonly array $records)
            {
            }

            public function addresses(string $host): array
            {
                return $this->addresses;
            }

            public function records(string $name, string $type): array
            {
                return $this->records;
            }
        });
    }

    public function fakeTcp(?string $error = null): void
    {
        $this->app->instance(TcpProbe::class, new class ($error) extends TcpProbe {
            public function __construct(private readonly ?string $error)
            {
            }

            public function connect(string $address, int $port, float $timeout): void
            {
                if ($this->error !== null) {
                    throw new ProbeFailedException($this->error);
                }
            }
        });
    }

    public function fakeCertificate(CarbonImmutable $expiresAt): void
    {
        $this->app->instance(TlsCertificateReader::class, new class ($expiresAt) extends TlsCertificateReader {
            public function __construct(private readonly CarbonImmutable $expiresAt)
            {
            }

            public function expiresAt(string $address, string $host, int $port, float $timeout): CarbonImmutable
            {
                return $this->expiresAt;
            }
        });
    }
}
