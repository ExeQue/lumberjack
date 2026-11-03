<?php

declare(strict_types=1);

namespace App\Domains\Screens\Eloquent\Builders;

use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Screen>
 */
class ScreenBuilder extends Builder
{
    public function whereTeam(Team $team): self
    {
        return $this->where('team_id', $team->id);
    }

    public function orWhereTeam(Team $team): self
    {
        return $this->orWhere('team_id', $team->id);
    }
}
