<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Domains\Teams\Models\TeamInvitation;
use App\Domains\Teams\Notifications\TeamInvitationNotification;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteMemberAction
{
    public const int EXPIRES_AFTER_DAYS = 7;

    /**
     * Ownership is not handed out by invitation, except by a system administrator
     * creating a team for someone who has no account yet ($allowOwner).
     */
    public function execute(Team $team, string $email, TeamRole $role, User $invitedBy, bool $allowOwner = false): TeamInvitation
    {
        $email = Str::lower(trim($email));
        $token = Str::random(48);

        $invitation = $team->invitations()->updateOrCreate(
            ['email' => $email],
            [
                'role' => $role === TeamRole::Owner && ! $allowOwner ? TeamRole::Admin : $role,
                'invited_by_id' => $invitedBy->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
            ],
        );

        Notification::route('mail', $email)->notify(new TeamInvitationNotification($invitation, $token));

        return $invitation;
    }
}
