<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSourceConnection>
 */
class DataSourceConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'url' => 'https://app.example.com/lumberjack/data-sources',
            'pull_token' => 'lj_pull_test',
        ];
    }

    /** The app was last seen answering. */
    public function answering(): static
    {
        return $this->state(fn (): array => ['last_checked_at' => now(), 'last_ok_at' => now()]);
    }
}
