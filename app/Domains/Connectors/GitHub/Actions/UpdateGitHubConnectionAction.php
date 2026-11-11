<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Actions;

use App\Domains\Connectors\GitHub\Data\GitHubConfig;
use App\Domains\Connectors\GitHub\Enums\GitHubFigure;
use App\Domains\Connectors\GitHub\Exceptions\GitHubBranchNotFoundException;
use App\Domains\Connectors\GitHub\Exceptions\GitHubRequestFailedException;
use App\Domains\Connectors\GitHub\Support\GitHubClient;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Changes a connected repository's settings: the branch its commits, CI status and failed
 * runs are read from, once GitHub says the repository has it (null goes back to the default
 * branch), and the key figures its page shows (null keeps them as they are). Only a figure
 * ticked otherwise than its default is remembered, so the others keep following it, such as
 * the stars of a repository made public. The data sources stay as they are either way.
 */
class UpdateGitHubConnectionAction
{
    public function __construct(
        private readonly GitHubClient $client,
    ) {
    }

    /**
     * @param  list<GitHubFigure>|null  $figures  those ticked, out of those the token can show
     *
     * @throws GitHubBranchNotFoundException
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    public function execute(DataSourceConnection $connection, ?string $branch, ?array $figures = null): DataSourceConnection
    {
        $config = $connection->config;

        if (! $config instanceof GitHubConfig) {
            throw new GitHubRequestFailedException(__('connectors.github.errors.not_github'));
        }

        $overrides = $figures === null ? $config->figures : $this->overrides($connection, $config, $figures);
        $branchChanged = $config->branch !== $branch;

        if (! $branchChanged && $config->figures == $overrides) {
            return $connection;
        }

        if ($branchChanged && $branch !== null) {
            $this->client->verifyBranch($connection->gitHubCredentials(), $branch);
        }

        $connection->config = $config->withSettings($branch, $overrides);

        if ($branchChanged) {
            // The catalog names the branch the CI status is read from.
            $connection->fill(['last_error' => null, 'next_check_at' => null]);
        }

        $connection->save();

        if ($branchChanged) {
            // What was read from the other branch is not this one's.
            DataSourceWindow::query()->whereIn('data_source_id', $connection->sources()->select('id'))->delete();
        }

        return $connection;
    }

    /**
     * The figures ticked otherwise than by default, with those the token cannot show (and so
     * were not offered) kept as they were.
     *
     * @param  list<GitHubFigure>  $ticked
     * @return array<string, bool>
     *
     * @throws GitHubRequestFailedException
     * @throws DecryptException
     */
    private function overrides(DataSourceConnection $connection, GitHubConfig $config, array $ticked): array
    {
        $private = $this->client->repository($connection->gitHubCredentials())['private'];
        $overrides = $config->figures;

        foreach (GitHubFigure::cases() as $figure) {
            if ($figure->permission() !== null && ! in_array($figure->permission(), $config->granted, true)) {
                continue;
            }

            $shown = in_array($figure, $ticked, true);

            if ($shown === $figure->shownByDefault($private)) {
                unset($overrides[$figure->value]);
            } else {
                $overrides[$figure->value] = $shown;
            }
        }

        return $overrides;
    }
}
