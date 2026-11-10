<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Actions;

use App\Domains\Connectors\Umami\Data\UmamiCredentials;
use App\Domains\Connectors\Umami\Exceptions\UmamiRequestFailedException;
use App\Domains\Connectors\Umami\Support\UmamiClient;

/**
 * The websites a set of credentials can read, by name, to pick the one a project reads.
 */
class ListUmamiWebsitesAction
{
    public function __construct(
        private readonly UmamiClient $client,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, domain: string|null, team: string|null}>
     *
     * @throws UmamiRequestFailedException
     */
    public function execute(UmamiCredentials $credentials): array
    {
        $websites = $this->client->websites($credentials);
        usort($websites, fn (array $a, array $b): int => [$a['team'] !== null, strtolower((string) $a['team']), strtolower($a['name'])] <=> [$b['team'] !== null, strtolower((string) $b['team']), strtolower($b['name'])]);

        return $websites;
    }
}
