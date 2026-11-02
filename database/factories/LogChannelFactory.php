<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogChannel>
 */
class LogChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->slug(2),
        ];
    }
}
