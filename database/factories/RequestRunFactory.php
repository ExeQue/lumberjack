<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestRun>
 */
class RequestRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'method' => 'GET',
            'route' => '/dashboard',
            'domain' => 'example.test',
            'url' => 'https://example.test/dashboard',
            'status_code' => 200,
            'duration_us' => fake()->numberBetween(10_000, 500_000),
            'occurred_at' => now(),
        ];
    }
}
