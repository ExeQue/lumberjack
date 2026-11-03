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
     * Its token stops working at once, and the TV goes back to showing a pairing code.
     */
    public function execute(Display $display, User $user): void
    {
        $display->delete();
        $this->audit->execute($user, AdminAction::DisplayRemoved, $display->name, ['display' => $display->uuid]);
        DisplayChanged::dispatch($display, true);
    }
}
