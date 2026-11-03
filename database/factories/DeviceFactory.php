<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Support\TvCredentials;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'Office TV',
            'device_hash' => TvCredentials::hash(Str::random(40)),
            'user_agent' => 'Mozilla/5.0 (Linux; Android 9; AFTMM) AppleWebKit/537.36 (KHTML, like Gecko) Silk/112.5.1 like Chrome/112.0.5615.213 Safari/537.36',
            'ip' => '10.0.0.20',
            'last_seen_at' => now(),
        ];
    }

    /** The browser whose device cookie carries this. */
    public function withCookie(string $device): static
    {
        return $this->state(fn () => ['device_hash' => TvCredentials::hash($device)]);
    }
}
