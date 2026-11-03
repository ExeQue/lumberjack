<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\ProjectAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HostSample>
 */
class HostSampleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_agent_id' => ProjectAgent::factory(),
            'cpu_load_1m' => 0.8,
            'cpu_cores' => 4,
            'memory_used_bytes' => 3 * 1024 ** 3,
            'memory_total_bytes' => 8 * 1024 ** 3,
            'disk_used_bytes' => 42 * 1024 ** 3,
            'disk_total_bytes' => 160 * 1024 ** 3,
            'php_memory_peak_bytes' => 48 * 1024 ** 2,
            'sampled_at' => now(),
        ];
    }

    /** A machine of this many cores, gigabytes of memory and of disk. */
    public function machine(int $cores, int $memoryGb, int $diskGb): static
    {
        return $this->state(fn () => ['cpu_cores' => $cores, 'memory_total_bytes' => $memoryGb * 1024 ** 3, 'disk_total_bytes' => $diskGb * 1024 ** 3]);
    }

    /** Busy as a share (0 to 1) of its cores, memory and disk. */
    public function load(float $cpu, float $memory, float $disk): static
    {
        return $this->state(fn (array $attributes) => [
            'cpu_load_1m' => round($cpu * $attributes['cpu_cores'], 2),
            'memory_used_bytes' => (int) ($memory * $attributes['memory_total_bytes']),
            'disk_used_bytes' => (int) ($disk * $attributes['disk_total_bytes']),
        ]);
    }
}
