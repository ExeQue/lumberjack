<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Performance\Models\QueryRun;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryRun>
 */
class QueryRunFactory extends Factory
{
    public function definition(): array
    {
        $sql = 'select * from `users` where `id` = ?';

        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'connection' => 'mysql',
            'sql' => $sql,
            'sql_hash' => hash('sha256', $sql),
            'duration_us' => fake()->numberBetween(100, 50_000),
            'occurred_at' => now(),
        ];
    }
}
