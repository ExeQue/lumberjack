<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Models\Device;

class UpdateDeviceAction
{
    /**
     * Its name; what its windows show is set per window.
     *
     * @param  array{name?: string}  $settings
     */
    public function execute(Device $device, array $settings): Device
    {
        $device->fill(array_intersect_key($settings, array_flip(['name'])))->save();

        return $device;
    }
}
