<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Policies;

use App\Domains\DataSources\Models\DataSource;
use App\Models\User;

/**
 * The same as a project's health checks: its team sees them, whoever administers it changes them.
 */
class DataSourcePolicy
{
    public function view(User $user, DataSource $source): bool
    {
        return $user->belongsToTeam($source->project->team);
    }

    /**
     * Edit, delete and fetch now.
     */
    public function update(User $user, DataSource $source): bool
    {
        return $user->canAdministerTeam($source->project->team);
    }
}
