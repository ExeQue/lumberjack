<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledTask>
 */
class ScheduledTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'name' => 'artisan reports:send',
            'expression' => '0 * * * *',
            'timezone' => 'UTC',
            'last_status' => ScheduledRunStatus::Finished,
            'last_run_at' => now()->startOfHour(),
            'last_duration_us' => 1_200_000,
            'next_run_at' => now()->startOfHour()->addHour(),
            'last_seen_at' => now()->startOfHour(),
        ];
    }
}
