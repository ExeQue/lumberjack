<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Actions;

use App\Domains\Connectors\Packagist\Data\PackagistConfig;
use App\Domains\Connectors\Packagist\Exceptions\PackagistRequestFailedException;
use App\Domains\Connectors\Packagist\Support\PackagistClient;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;

/**
 * Connects a project to a Packagist package, once Packagist knows it. A project reads as
 * many as it likes; connecting the same package again renews that connection.
 */
class ConnectPackagistAction
{
    public function __construct(
        private readonly PackagistClient $client,
    ) {
    }

    /**
     * @throws PackagistRequestFailedException when Packagist has no such package
     */
    public function execute(Project $project, string $package): DataSourceConnection
    {
        $found = $this->client->package($package);

        $connection = $project->packagistConnections()->get()->first(fn (DataSourceConnection $c): bool => $c->packagistConfig()->package === $found['name'])
            ?? new DataSourceConnection([
                'project_id' => $project->id,
                'kind' => ConnectionKind::Packagist,
                'environment' => ConnectionKind::Packagist->environment(),
            ]);

        $connection->fill([
            'url' => PackagistClient::HOST,
            'name' => $found['name'],
            'config' => new PackagistConfig($found['name']),
            // Packagist counts its days in UTC.
            'timezone' => 'UTC',
            'last_error' => null,
            // Its sources are listed within a minute.
            'next_check_at' => null,
        ])->save();

        return $connection;
    }
}
