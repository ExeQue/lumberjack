<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Actions;

use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\GitHub\Data\GitHubCredentials;
use App\Domains\Connectors\GitHub\Exceptions\GitHubBranchNotFoundException;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;

/**
 * Connects a project to a GitHub repository, once the token proves it reads the repository
 * and everything the connector requires, and the repository has the branch chosen, if one is,
 * remembering which optional permissions it has. A project reads as many repositories as it
 * likes, one connection each; connecting a repository it already reads renews that
 * connection's token and branch.
 */
class ConnectGitHubAction
{
    public function __construct(
        private readonly GitHubClient $client,
    ) {
    }

    /**
     * @throws GitHubRequestFailedException naming what the token cannot read
     * @throws GitHubBranchNotFoundException
     */
    public function execute(Project $project, GitHubCredentials $credentials): DataSourceConnection
    {
        // GitHub's own spelling, so the same repository typed otherwise is the same connection.
        $repository = $this->client->verify($credentials)['full_name'];

        if ($credentials->branch !== null) {
            $this->client->verifyBranch($credentials, $credentials->branch);
        }

        // Asked again on every renewal, as a new token may have more or fewer.
        $granted = $this->client->grantedPermissions($credentials);

        $connection = $project->gitHubConnections()->where('name', $repository)->first()
            ?? new DataSourceConnection([
                'project_id' => $project->id,
                'kind' => ConnectionKind::GitHub,
                'environment' => ConnectionKind::GitHub->environment(),
            ]);

        $connection->fill([
            'url' => 'https://github.com/'.$repository,
            'name' => $repository,
            'config' => new GitHubConfig($repository, $credentials->token, $granted, $credentials->branch),
            'last_error' => null,
            // Its sources are listed within a minute.
            'next_check_at' => null,
        ])->save();

        return $connection;
    }
}
