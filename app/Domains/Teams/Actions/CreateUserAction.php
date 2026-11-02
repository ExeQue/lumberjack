<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A user starts with a personal team of their own, unless asked not to (the
 * first administrator, who sets up the teams themselves).
 */
class CreateUserAction
{
    public function __construct(
        private readonly CreateTeamAction $createTeamAction,
    ) {
    }

    public function execute(string $name, string $email, string $password, bool $verified = true, bool $personalTeam = true, bool $admin = false): User
    {
        return DB::transaction(function () use ($name, $email, $password, $verified, $personalTeam, $admin): User {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => $verified ? now() : null, 'is_admin' => $admin])->save();

            if ($personalTeam) {
                $this->createTeamAction->execute($user, __('teams.personal_team_name', ['name' => $name]), personal: true);
            }

            return $user->refresh();
        });
    }
}
