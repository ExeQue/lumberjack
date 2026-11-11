<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Actions;

use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\GitHub\Enums\GitHubSource;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use App\Domains\DataSources\Models\DataSourceConnection;

/**
 * The sources a GitHub repository offers, as an app's index would list them, leaving out
 * those needing an optional permission the token did not have when it was connected. GitHub
 * is asked once, so a token that no longer works shows on the connection.
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
        $granted = GitHubConfig::grantedBy($connection->config);
        $branch = GitHubConfig::branchOf($connection->config);
        $offered = array_filter(GitHubSource::cases(), fn (GitHubSource $source): bool => $source->permission() === null || in_array($source->permission(), $granted, true));

        return ['version' => 1, 'complete' => true, 'sources' => array_values(array_map(fn (GitHubSource $source): array => [
            'key' => $source->value,
            'label' => $source->label($branch),
            'shape' => $source->shape()->value,
            'group' => [],
        ], $offered))];
    }
}
