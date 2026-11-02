<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<JobRun>
 */
class JobRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'job_uuid' => (string) Str::uuid(),
            'name' => 'App\\Jobs\\SendWelcomeMail',
            'connection' => 'redis',
            'queue' => 'default',
            'attempt' => 1,
            'status' => JobRunStatus::Processed,
            'queued_at' => now()->subSeconds(10),
            'started_at' => now()->subSeconds(8),
            'finished_at' => now(),
            'duration_us' => 8_000_000,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => JobRunStatus::Processing, 'finished_at' => null, 'duration_us' => null]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => JobRunStatus::Failed]);
    }
}
