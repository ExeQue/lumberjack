<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Actions;

use App\Domains\Connectors\Mosparo\Data\MosparoConfig;
use App\Domains\Connectors\Mosparo\Data\MosparoCredentials;
use App\Domains\Connectors\Mosparo\Exceptions\MosparoRequestFailedException;
use App\Domains\Connectors\Mosparo\Support\MosparoClient;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;

/**
 * Connects a project to a mosparo project, once mosparo takes its keys. A project reads as
 * many as it likes; connecting the same public key again renews that connection.
 */
class ConnectMosparoAction
{
    public function __construct(
        private readonly MosparoClient $client,
    ) {
    }

    /**
     * @throws MosparoRequestFailedException when mosparo refuses the keys
     */
    public function execute(Project $project, MosparoCredentials $credentials, string $name, string $timezone = 'UTC'): DataSourceConnection
    {
        $this->client->statistics($credentials, 60);

        $connection = $project->mosparoConnections()->get()->first(fn (DataSourceConnection $c): bool => $c->url === $credentials->host && $c->mosparoCredentials()->publicKey === $credentials->publicKey)
            ?? new DataSourceConnection([
                'project_id' => $project->id,
                'kind' => ConnectionKind::Mosparo,
                'environment' => ConnectionKind::Mosparo->environment(),
            ]);

        $connection->fill([
            'url' => $credentials->host,
            'name' => mb_substr($name, 0, 200),
            'config' => new MosparoConfig($credentials->publicKey, $credentials->privateKey),
            'timezone' => $timezone,
            'last_error' => null,
            // Its sources are listed within a minute.
            'next_check_at' => null,
        ])->save();

        return $connection;
    }
}
