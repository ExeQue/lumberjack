<?php

declare(strict_types=1);

namespace App\Domains\Administration\Actions;

use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Exceptions\LastAdminException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A suspended user is signed out at once and cannot sign in, and their MCP
 * tokens stop working, until an administrator reinstates them.
 */
class SetSuspendedAction
{
    public function __construct(
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    public function execute(User $admin, User $user, bool $suspended): User
    {
        if ($admin->is($user)) {
            throw LastAdminException::self();
        }

        if ($suspended && $user->isAdmin() && User::query()->whereActiveAdmin()->whereKeyNot($user->id)->doesntExist()) {
            throw LastAdminException::create();
        }

        $user->forceFill(['suspended_at' => $suspended ? now() : null])->save();

        if ($suspended) {
            // Database sessions end now; other drivers end on the next request.
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $this->audit->execute($admin, $suspended ? AdminAction::UserSuspended : AdminAction::UserReinstated, $user->email);

        return $user;
    }
}
