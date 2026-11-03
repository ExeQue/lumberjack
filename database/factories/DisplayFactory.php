<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Screens\Enums\DisplayTheme;
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
            'screen_id' => null,
            'name' => 'Office TV',
            'theme' => DisplayTheme::Default,
            'token_hash' => TvCredentials::hash(TvCredentials::newDisplayToken()),
            'user_agent' => 'Mozilla/5.0 (Linux; Android 9; AFTMM) AppleWebKit/537.36 (KHTML, like Gecko) Silk/112.5.1 like Chrome/112.0.5615.213 Safari/537.36',
            'ip' => '10.0.0.20',
            'last_seen_at' => now(),
        ];
    }

    /** Showing a screen of its own team. */
    public function showing(Screen $screen): static
    {
        return $this->state(fn () => ['team_id' => $screen->team_id, 'screen_id' => $screen->id]);
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
