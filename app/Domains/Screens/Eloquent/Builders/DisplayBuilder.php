<?php

declare(strict_types=1);

namespace App\Domains\Screens\Eloquent\Builders;

use App\Domains\Screens\Models\Display;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Display>
 */
class DisplayBuilder extends Builder
{
    public function whereTeam(Team $team): self
    {
        return $this->where('team_id', $team->id);
    }

    public function orWhereTeam(Team $team): self
    {
        return $this->orWhere('team_id', $team->id);
    }

    /** The display whose token hashes to this. */
    public function whereTokenHash(string $hash): self
    {
        return $this->where('token_hash', $hash);
    }

    public function orWhereTokenHash(string $hash): self
    {
        return $this->orWhere('token_hash', $hash);
    }
}
