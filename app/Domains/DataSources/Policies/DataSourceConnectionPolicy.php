<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Policies;

use App\Domains\DataSources\Models\DataSourceConnection;
use App\Models\User;

/**
 * An app's or a service's connection: its project's team sees what it reads, whoever
 * administers the team connects, changes and removes it.
 */
class DataSourceConnectionPolicy
{
    public function view(User $user, DataSourceConnection $connection): bool
    {
        return $user->belongsToTeam($connection->project->team);
    }

    /**
     * Change its address, credentials or website, rotate its token, and disconnect it.
     */
    public function update(User $user, DataSourceConnection $connection): bool
    {
        return $user->canAdministerTeam($connection->project->team);
    }
}
