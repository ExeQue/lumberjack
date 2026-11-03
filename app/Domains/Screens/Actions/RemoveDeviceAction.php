<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Screens\Events\DisplayChanged;
use App\Domains\Screens\Models\Device;
use App\Models\User;

class RemoveDeviceAction
{
    public function __construct(
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    /**
     * Unpairs every window of the device: their tokens stop working at once, and each goes
     * back to showing a pairing code. Answers how many windows it had.
     */
    public function execute(Device $device, User $user): int
    {
        $displays = $device->displays()->get();
        $device->delete();
        $this->audit->execute($user, AdminAction::DisplayRemoved, $device->name, ['device' => $device->uuid, 'displays' => $displays->pluck('uuid')->all()]);

        foreach ($displays as $display) {
            DisplayChanged::dispatch($display, true);
        }

        return $displays->count();
    }
}
