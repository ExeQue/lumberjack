<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueNote>
 */
class IssueNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
