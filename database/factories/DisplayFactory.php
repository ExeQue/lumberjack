<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\TvCredentials;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Display>
 */
class DisplayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            // A device of its own, in its team, unless the test gives it one with onDevice().
            'device_id' => fn (array $attributes) => Device::factory()->state(['team_id' => $attributes['team_id']]),
            'screen_id' => null,
            'name' => null,
            'theme' => DisplayTheme::Default,
            'token_hash' => TvCredentials::hash(TvCredentials::newDisplayToken()),
            'last_seen_at' => now(),
        ];
    }

    /** Showing a screen of its own team. */
    public function showing(Screen $screen): static
    {
        return $this->state(fn () => ['team_id' => $screen->team_id, 'screen_id' => $screen->id]);
    }

    /** Another window of this device. */
    public function onDevice(Device $device, int $slot = 1): static
    {
        return $this->state(fn () => ['team_id' => $device->team_id, 'device_id' => $device->id, 'slot' => $slot]);
    }

    /** Holding this token, as the TV's cookie would. */
    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => TvCredentials::hash($token)]);
    }

    /** Not heard from in days. */
    public function offline(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subDays(3)]);
    }
}
