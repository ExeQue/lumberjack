<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Actions;

use App\Domains\Connectors\Mosparo\Enums\MosparoSource;
use App\Domains\Connectors\Mosparo\Exceptions\MosparoRequestFailedException;
use App\Domains\Connectors\Mosparo\Support\MosparoClient;
use App\Domains\DataSources\Models\DataSourceConnection;

/**
 * The sources a mosparo project offers, as an app's index would list them. mosparo is
 * asked once, so a key that no longer works shows on the connection.
 */
class BuildMosparoCatalogAction
{
    public function __construct(
        private readonly MosparoClient $client,
    ) {
    }

    /**
     * @return array{version: int, complete: bool, sources: list<array{key: string, label: string, shape: string, group: list<string>}>}
     *
     * @throws MosparoRequestFailedException
     */
    public function execute(DataSourceConnection $connection): array
    {
        $this->client->statistics($connection->mosparoCredentials(), 3600);

        return ['version' => 1, 'complete' => true, 'sources' => array_map(fn (MosparoSource $source): array => [
            'key' => $source->value,
            'label' => $source->label(),
            'shape' => $source->shape()->value,
            'group' => [],
        ], MosparoSource::cases())];
    }
}
