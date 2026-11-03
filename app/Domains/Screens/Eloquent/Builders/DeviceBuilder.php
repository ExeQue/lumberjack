<?php

declare(strict_types=1);

namespace App\Domains\Screens\Eloquent\Builders;

use App\Domains\Screens\Models\Device;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Device>
 */
class DeviceBuilder extends Builder
{
    public function whereTeam(Team|int $team): self
    {
        return $this->where('team_id', $team instanceof Team ? $team->id : $team);
    }

    public function orWhereTeam(Team|int $team): self
    {
        return $this->orWhere('team_id', $team instanceof Team ? $team->id : $team);
    }

    /** The device whose cookie hashes to this. */
    public function whereDeviceHash(string $hash): self
    {
        return $this->where('device_hash', $hash);
    }

    public function orWhereDeviceHash(string $hash): self
    {
        return $this->orWhere('device_hash', $hash);
    }

    /** Its windows, in window order. */
    public function withDisplays(): self
    {
        return $this->with(['displays' => fn ($q) => $q->orderBy('slot')]);
    }
}
