<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectAgent>
 */
class ProjectAgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'host' => 'web-'.fake()->unique()->numberBetween(1, 99),
            'version' => '0.1.0',
            'last_seen_at' => now(),
        ];
    }

    public function offline(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subMinutes(30)]);
    }
}
