<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Occurrence>
 */
class OccurrenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'project_id' => fn (array $attributes) => $attributes['issue_id'] instanceof Issue ? $attributes['issue_id']->project_id : Issue::query()->whereKey($attributes['issue_id'])->value('project_id'),
            'environment' => 'production',
            'stacktrace' => [],
            'context' => [],
            'occurred_at' => now(),
        ];
    }
}
