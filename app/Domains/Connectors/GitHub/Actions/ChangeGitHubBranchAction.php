<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Actions;

use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\GitHub\Exceptions\GitHubBranchNotFoundException;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Changes the branch a connected repository's commits, CI status and failed runs are read
 * from, once GitHub says the repository has it; null goes back to the default branch.
 */
class ChangeGitHubBranchAction
{
    public function __construct(
        private readonly GitHubClient $client,
    ) {
    }

    /**
     * @throws GitHubBranchNotFoundException
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function execute(DataSourceConnection $connection, ?string $branch): DataSourceConnection
    {
        $config = $connection->config;

        if (! $config instanceof GitHubConfig) {
            throw new GitHubRequestFailedException(__('connectors.github.errors.not_github'));
        }

        if ($config->branch === $branch) {
            return $connection;
        }

        if ($branch !== null) {
            $this->client->verifyBranch($connection->gitHubCredentials(), $branch);
        }

        $connection->fill([
            'config' => $config->withBranch($branch),
            'last_error' => null,
            // The catalog names the branch the CI status is read from.
            'next_check_at' => null,
        ])->save();

        // What was read from the other branch is not this one's.
        DataSourceWindow::query()->whereIn('data_source_id', $connection->sources()->select('id'))->delete();

        return $connection;
    }
}
