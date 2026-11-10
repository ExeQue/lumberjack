<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Actions;

use App\Domains\Connectors\Umami\Data\UmamiConfig;
use App\Domains\Connectors\Umami\Data\UmamiCredentials;
use App\Domains\Connectors\Umami\Exceptions\UmamiRequestFailedException;
use App\Domains\Connectors\Umami\Exceptions\UmamiWebsiteAlreadyConnectedException;
use App\Domains\Connectors\Umami\Support\UmamiClient;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Projects\Models\Project;

/**
 * Connects a project to an Umami website, or points one of its connections at another. A
 * project reads as many websites as it likes, one connection each; connecting a website it
 * already reads renews that connection's credentials.
 */
class ConnectUmamiAction
{
    public function __construct(
        private readonly UmamiClient $client,
    ) {
    }

    /**
     * @throws UmamiRequestFailedException when the credentials cannot read the website
     */
    public function execute(Project $project, UmamiCredentials $credentials, string $websiteId, string $timezone = 'UTC'): DataSourceConnection
    {
        $website = $this->client->website($credentials, $websiteId);

        $connection = $this->reading($project, $websiteId) ?? new DataSourceConnection([
            'project_id' => $project->id,
            'kind' => ConnectionKind::Umami,
            'environment' => ConnectionKind::Umami->environment(),
        ]);

        $connection->fill([
            'url' => $credentials->baseUrl,
            'name' => mb_substr($website['name'], 0, 200),
            'config' => new UmamiConfig($websiteId, $credentials->usesLogin() ? $credentials->username : null, $credentials->secret),
            'timezone' => $timezone,
            'last_error' => null,
            // Asked within a minute which events it has.
            'next_check_at' => null,
        ])->save();

        return $connection;
    }

    /**
     * Points a connection at another website its credentials can read; the screens showing
     * its sources go on with the new one.
     *
     * @throws UmamiRequestFailedException
     * @throws UmamiWebsiteAlreadyConnectedException
     */
    public function changeWebsite(DataSourceConnection $connection, string $websiteId): DataSourceConnection
    {
        if ($websiteId === $connection->umamiWebsiteId()) {
            return $connection;
        }

        if ($this->reading($connection->project, $websiteId) !== null) {
            throw new UmamiWebsiteAlreadyConnectedException(__('connectors.umami.errors.already_connected'));
        }

        $website = $this->client->website($connection->umamiCredentials(), $websiteId);

        $connection->fill([
            'name' => mb_substr($website['name'], 0, 200),
            'config' => $connection->umamiConfig()->withWebsite($websiteId),
            'last_error' => null,
            'next_check_at' => null,
        ])->save();

        // What was read from the other website is not this one's.
        DataSourceWindow::query()->whereIn('data_source_id', $connection->sources()->select('id'))->delete();

        return $connection;
    }

    /**
     * The project's connection reading the website, if any; the website is kept encrypted,
     * so the project's few are read one by one.
     */
    private function reading(Project $project, string $websiteId): ?DataSourceConnection
    {
        return $project->umamiConnections()->get()->first(fn (DataSourceConnection $connection): bool => $connection->umamiWebsiteId() === $websiteId);
    }
}
