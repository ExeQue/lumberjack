<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Actions;

use App\Domains\Connectors\GitHub\Enums\GitHubSource;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use App\Domains\DataSources\Models\DataSourceConnection;

/**
 * The sources a GitHub repository offers, as an app's index would list them. GitHub is
 * asked once, so a token that no longer works shows on the connection.
 */
class BuildGitHubCatalogAction
{
    public function __construct(
        private readonly GitHubClient $client,
    ) {
    }

    /**
     * @return array{version: int, complete: bool, sources: list<array{key: string, label: string, shape: string, group: list<string>}>}
     *
     * @throws GitHubRequestFailedException
     */
    public function execute(DataSourceConnection $connection): array
    {
        $this->client->repository($connection->gitHubCredentials());

        return ['version' => 1, 'complete' => true, 'sources' => array_map(fn (GitHubSource $source): array => [
            'key' => $source->value,
            'label' => $source->label(),
            'shape' => $source->shape()->value,
            'group' => [],
        ], GitHubSource::cases())];
    }
}
