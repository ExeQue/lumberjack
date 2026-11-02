<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }
}
