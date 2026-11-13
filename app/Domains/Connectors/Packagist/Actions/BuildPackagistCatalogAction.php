<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Actions;

use App\Domains\Connectors\Packagist\Enums\PackagistSource;
use App\Domains\Connectors\Packagist\Exceptions\PackagistRequestFailedException;
use App\Domains\Connectors\Packagist\Queries\PackagistDashboardQuery;
use App\Domains\DataSources\Models\DataSourceConnection;

/**
 * The sources a Packagist package offers, as an app's index would list them. Packagist is
 * asked once, so a package that is gone shows on the connection.
 */
class BuildPackagistCatalogAction
{
    public function __construct(
        private readonly PackagistDashboardQuery $query,
    ) {
    }

    /**
     * @return array{version: int, complete: bool, sources: list<array{key: string, label: string, shape: string, group: list<string>}>}
     *
     * @throws PackagistRequestFailedException
     */
    public function execute(DataSourceConnection $connection): array
    {
        $this->query->package($connection);

        return ['version' => 1, 'complete' => true, 'sources' => array_map(fn (PackagistSource $source): array => [
            'key' => $source->value,
            'label' => $source->label(),
            'shape' => $source->shape()->value,
            'group' => [],
        ], PackagistSource::cases())];
    }
}
