<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\RemoveDeviceAction;
use App\Domains\Screens\Actions\RequestReloadAction;
use App\Domains\Screens\Actions\UpdateDeviceAction;
use App\Domains\Screens\Exceptions\ReloadTooSoonException;
use App\Domains\Screens\Http\Requests\UpdateDeviceRequest;
use App\Domains\Screens\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Team settings > Displays, per device: its name, and reloading or unpairing all its
 * windows at once. Team admins only.
 */
class DeviceController
{
    public function update(UpdateDeviceRequest $request, Device $device, UpdateDeviceAction $action): RedirectResponse
    {
        $action->execute($device, $request->settings());

        return back()->with('status', __('screens.displays.device_saved'));
    }

    /** Every window loads its page afresh; at most once every few seconds. */
    public function reload(Device $device, RequestReloadAction $action): RedirectResponse
    {
        Gate::authorize('update', $device);

        try {
            $windows = (int) $action->execute($device);
        } catch (ReloadTooSoonException $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', trans_choice('screens.reload.sent_device', $windows, ['name' => $device->name]));
    }

    public function destroy(Request $request, Device $device, RemoveDeviceAction $action): RedirectResponse
    {
        Gate::authorize('delete', $device);
        $windows = $action->execute($device, $request->user());

        return back()->with('status', trans_choice('screens.displays.device_removed', $windows, ['name' => $device->name]));
    }
}
