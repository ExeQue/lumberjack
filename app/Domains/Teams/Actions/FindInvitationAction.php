<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Teams\Exceptions\InvalidInvitationException;
use App\Domains\Teams\Models\TeamInvitation;

class FindInvitationAction
{
    public function execute(string $token): TeamInvitation
    {
        $invitation = TeamInvitation::query()->where('token_hash', hash('sha256', $token))->first();

        if ($invitation === null || $invitation->isExpired()) {
            throw InvalidInvitationException::expiredOrUnknown();
        }

        return $invitation;
    }
}
