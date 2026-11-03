<?php

declare(strict_types=1);

namespace App\Domains\Screens\Broadcasting;

use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Str;

/**
 * team.{uuid}.displays: where Team settings > Displays and the designer hear that a window
 * of the team reported in (its size, last seen). The team's members listen; TVs do not.
 */
class TeamDisplaysChannel
{
    public static function for(Team $team): PrivateChannel
    {
        return new PrivateChannel(self::name($team));
    }

    public static function name(Team $team): string
    {
        return 'team.'.$team->uuid.'.displays';
    }

    public function join(User $user, string $teamUuid): bool
    {
        $team = Str::isUuid($teamUuid) ? Team::query()->where('uuid', $teamUuid)->first() : null;

        return $team !== null && $user->belongsToTeam($team);
    }
}
