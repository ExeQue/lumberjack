<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Support\TvCredentials;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TvPairing>
 */
class TvPairingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => TvCredentials::newCode(),
            'secret_hash' => TvCredentials::hash(TvCredentials::newSecret()),
            'user_agent' => 'Mozilla/5.0 (Linux; Android 9; AFTMM) AppleWebKit/537.36 (KHTML, like Gecko) Silk/112.5.1 like Chrome/112.0.5615.213 Safari/537.36',
            'ip' => '10.0.0.20',
            'expires_at' => now()->addMinutes(TvPairing::LIFETIME_MINUTES),
        ];
    }

    /** Held by the browser whose pairing cookie carries this secret. */
    public function withSecret(string $secret): static
    {
        return $this->state(fn () => ['secret_hash' => TvCredentials::hash($secret)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    /** Confirmed for this display, waiting for the TV to pick up its token. */
    public function confirmedFor(Display $display): static
    {
        return $this->state(fn () => ['display_id' => $display->id, 'confirmed_at' => now()]);
    }
}
