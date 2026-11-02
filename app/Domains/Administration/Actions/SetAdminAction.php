<?php

declare(strict_types=1);

namespace App\Domains\Administration\Actions;

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Exceptions\LastAdminException;
use App\Models\User;

/**
 * Makes a user a system administrator, or not. Lumberjack always keeps one,
 * and an administrator cannot take it from themselves.
 */
class SetAdminAction
{
    public function __construct(
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    public function execute(User $admin, User $user, bool $isAdmin): User
    {
        if (! $isAdmin && $admin->is($user)) {
            throw LastAdminException::self();
        }

        if (! $isAdmin && User::query()->whereActiveAdmin()->whereKeyNot($user->id)->doesntExist()) {
            throw LastAdminException::create();
        }

        $user->forceFill(['is_admin' => $isAdmin])->save();
        $this->audit->execute($admin, $isAdmin ? AdminAction::AdminGranted : AdminAction::AdminRevoked, $user->email);

        return $user;
    }
}
