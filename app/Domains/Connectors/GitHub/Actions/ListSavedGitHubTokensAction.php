<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Actions;

use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * The GitHub tokens already saved in a project's team, for connecting another repository
 * without pasting the same token again: one per token, by the first connection that holds
 * it, with every repository it reads. Only for those who may change the project, and so
 * every project of the team; a token saved under another APP_KEY cannot be read and is left
 * out. The repository integration's token is not among them, as it writes issues.
 */
class ListSavedGitHubTokensAction
{
    /**
     * @return list<array{connection: string, repositories: list<string>, token: string}>
     */
    public function execute(Project $project, User $user): array
    {
        if (! $user->can('update', $project)) {
            return [];
        }

        $saved = [];

        $connections = DataSourceConnection::query()
            ->where('kind', ConnectionKind::GitHub)
            ->whereIn('project_id', $project->team->projects()->select('id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        foreach ($connections as $connection) {
            try {
                $config = $connection->gitHubConfig();
            } catch (DecryptException) {
                continue;
            }

            if ($config === null || $config->token === '') {
                continue;
            }

            $saved[$config->token] ??= ['connection' => $connection->uuid, 'repositories' => [], 'token' => $config->token];

            if (! in_array($config->repository, $saved[$config->token]['repositories'], true)) {
                $saved[$config->token]['repositories'][] = $config->repository;
            }
        }

        return array_values($saved);
    }
}
