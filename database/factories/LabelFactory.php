<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Issues\Enums\LabelColor;
use App\Domains\Issues\Models\Label;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Label>
 */
class LabelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->randomElement(['payments', 'checkout', 'flaky', 'customer-facing', 'infra', 'needs-repro', 'p1', 'p2']),
            'color' => LabelColor::Blue,
        ];
    }
}
