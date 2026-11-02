<?php

declare(strict_types=1);

namespace App\Domains\Teams\Models;

use App\Domains\Teams\Enums\TeamRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $team_id
 * @property int $user_id
 * @property TeamRole $role
 */
class Membership extends Pivot
{
    protected $table = 'team_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
        ];
    }
}
