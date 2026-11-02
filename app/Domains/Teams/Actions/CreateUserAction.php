<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Models\User;

/**
 * A new account. It starts in no team: it joins one by invitation, or is made the
 * owner of one by whoever creates it.
 */
class CreateUserAction
{
    public function execute(string $name, string $email, string $password, bool $verified = true, bool $admin = false): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['email_verified_at' => $verified ? now() : null, 'is_admin' => $admin])->save();

        return $user;
    }
}
