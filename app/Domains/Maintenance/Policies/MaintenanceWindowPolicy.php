<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Policies;

use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Models\User;

class MaintenanceWindowPolicy
{
    public function view(User $user, MaintenanceWindow $window): bool
    {
        return $user->belongsToTeam($window->project->team);
    }

    /**
     * Edit and end: whoever administers the project, as for its health checks.
     */
    public function update(User $user, MaintenanceWindow $window): bool
    {
        return $user->canAdministerTeam($window->project->team);
    }
}
