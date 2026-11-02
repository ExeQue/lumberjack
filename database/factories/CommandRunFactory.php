<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Commands\Enums\CommandRunStatus;
use App\Domains\Commands\Models\CommandRun;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommandRun>
 */
class CommandRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'run_uuid' => (string) Str::uuid(),
            'environment' => 'production',
            'host' => 'web-1',
            'name' => 'reports:send',
            'arguments' => ['period' => 'daily'],
            'options' => ['force' => true],
            'status' => CommandRunStatus::Finished,
            'exit_code' => 0,
            'started_at' => now()->subSeconds(3),
            'finished_at' => now(),
            'duration_us' => 3_000_000,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => CommandRunStatus::Failed, 'exit_code' => 1]);
    }
}
