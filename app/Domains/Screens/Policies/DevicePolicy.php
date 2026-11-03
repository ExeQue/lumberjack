<?php

declare(strict_types=1);

namespace App\Domains\Screens\Policies;

use App\Domains\Screens\Models\Device;
use App\Models\User;

/**
 * Like its displays: team admins rename, reload and remove a device; members only see it.
 */
class DevicePolicy
{
    public function update(User $user, Device $device): bool
    {
        return $user->canAdministerTeam($device->team);
    }

    public function delete(User $user, Device $device): bool
    {
        return $user->canAdministerTeam($device->team);
    }
}
