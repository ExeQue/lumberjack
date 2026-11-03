<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Screens\Events\DisplayChanged;
use App\Domains\Screens\Models\Display;
use App\Models\User;

class RemoveDisplayAction
{
    public function __construct(
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    /**
     * Its token stops working at once, and the window goes back to showing a pairing code.
     * A device left without windows goes too.
     */
    public function execute(Display $display, User $user): void
    {
        $name = $display->fullName();
        $display->delete();
        $display->device()->whereDoesntHave('displays')->delete();
        $this->audit->execute($user, AdminAction::DisplayRemoved, $name, ['display' => $display->uuid]);
        DisplayChanged::dispatch($display, true);
    }
}
