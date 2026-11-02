<?php

declare(strict_types=1);

namespace App\Domains\Teams\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return __('teams.roles.'.$this->value);
    }

    /**
     * Owners and admins administer the team and its projects.
     */
    public function canAdminister(): bool
    {
        return $this !== self::Member;
    }
}
