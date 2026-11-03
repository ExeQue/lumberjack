<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueueSample>
 */
class QueueSampleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'host' => 'worker-1',
            'connection' => 'redis',
            'queue' => 'default',
            'size' => 0,
            'oldest_age_s' => null,
            'sampled_at' => now(),
        ];
    }

    public function forQueue(string $queue, string $connection = 'redis'): static
    {
        return $this->state(fn () => ['queue' => $queue, 'connection' => $connection]);
    }

    /** This many jobs waiting, the oldest for this many seconds. */
    public function waiting(int $size, ?int $oldestSeconds = null): static
    {
        return $this->state(fn () => ['size' => $size, 'oldest_age_s' => $size > 0 ? ($oldestSeconds ?? 5) : null]);
    }
}
