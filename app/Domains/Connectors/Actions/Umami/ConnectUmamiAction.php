<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Actions\Umami;

use App\Domains\Connectors\Data\Umami\UmamiCredentials;
use App\Domains\Connectors\Exceptions\Umami\UmamiRequestFailedException;
use App\Domains\Connectors\Exceptions\Umami\UmamiWebsiteAlreadyConnectedException;
use App\Domains\Connectors\Support\Umami\UmamiClient;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Str;

/**
 * Connects a project to an Umami website, or points one of its connections at another. A
 * project reads as many websites as it likes, one connection each; connecting a website it
 * already reads renews that connection's credentials.
 */
class ConnectUmamiAction
{
    /** The start of the environment each Umami connection is filed under, beside an app's. */
    public const string ENVIRONMENT = 'umami';

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

        $connection = $project->umamiConnections()->where('website_id', $websiteId)->first() ?? new DataSourceConnection([
            'project_id' => $project->id,
            'kind' => ConnectionKind::Umami,
            'environment' => self::environment(),
        ]);

        $connection->fill([
            'url' => $credentials->baseUrl,
            'username' => $credentials->usesLogin() ? $credentials->username : null,
            'secret' => $credentials->secret,
            'website_id' => $websiteId,
            'website_name' => mb_substr($website['name'], 0, 200),
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
        if ($websiteId === $connection->website_id) {
            return $connection;
        }

        if ($connection->project->umamiConnections()->where('website_id', $websiteId)->exists()) {
            throw new UmamiWebsiteAlreadyConnectedException(__('connectors.umami.errors.already_connected'));
        }

        $website = $this->client->website($connection->umamiCredentials(), $websiteId);

        $connection->fill([
            'website_id' => $websiteId,
            'website_name' => mb_substr($website['name'], 0, 200),
            'last_error' => null,
            'next_check_at' => null,
        ])->save();

        // What was read from the other website is not this one's.
        DataSourceWindow::query()->whereIn('data_source_id', $connection->sources()->select('id'))->delete();

        return $connection;
    }

    /** Unique within the project, so each website's sources are kept apart. */
    public static function environment(): string
    {
        return self::ENVIRONMENT.'-'.Str::lower(Str::random(8));
    }

    /** Whether an environment belongs to an Umami connection, and so cannot be an app's. */
    public static function isUmamiEnvironment(string $environment): bool
    {
        return preg_match('/^'.self::ENVIRONMENT.'(-|$)/i', $environment) === 1;
    }
}
