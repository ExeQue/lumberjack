<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Logs\Enums\LogLevel;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Logs\Models\LogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogEntry>
 */
class LogEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'log_channel_id' => LogChannel::factory(),
            'project_id' => fn (array $attributes) => $attributes['log_channel_id'] instanceof LogChannel ? $attributes['log_channel_id']->project_id : LogChannel::query()->whereKey($attributes['log_channel_id'])->value('project_id'),
            'level' => LogLevel::Warning,
            'message' => fake()->sentence(),
            'context' => ['key' => 'value'],
            'environment' => 'production',
            'occurred_at' => now(),
        ];
    }
}
