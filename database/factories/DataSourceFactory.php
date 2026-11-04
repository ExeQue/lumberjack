<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Orders API',
            'url' => 'https://example.com/stats.json',
            'headers' => [],
            'interval_minutes' => 5,
            'timeout_s' => 10,
        ];
    }
}
