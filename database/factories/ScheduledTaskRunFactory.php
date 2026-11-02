<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScheduledTaskRun>
 */
class ScheduledTaskRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scheduled_task_id' => ScheduledTask::factory(),
            'project_id' => fn (array $attributes): int => (int) ScheduledTask::query()->whereKey($attributes['scheduled_task_id'])->value('project_id'),
            'run_uuid' => (string) Str::uuid(),
            'environment' => 'production',
            'host' => 'web-1',
            'status' => ScheduledRunStatus::Finished,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
            'duration_us' => 2_000_000,
            'exit_code' => 0,
        ];
    }
}
