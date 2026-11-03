<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Screens\Events\ScreenUnshared;
use App\Domains\Screens\Models\Screen;
use App\Models\User;

class RotateScreenShareKeyAction
{
    public function __construct(
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    /**
     * A new share link; the old one stops working at once and its open viewers say so.
     * Only while the screen is shared.
     */
    public function execute(Screen $screen, User $user): ?string
    {
        if ($screen->share_key_hash === null) {
            return null;
        }

        $old = $screen->share_key_hash;
        $screen->update(SetScreenShareAction::newKey());
        $this->audit->execute($user, AdminAction::ScreenShareRotated, $screen->name, ['screen' => $screen->uuid]);
        ScreenUnshared::dispatch($old);

        return $screen->share_key;
    }
}
