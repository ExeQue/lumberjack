<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'source' => IssueSource::Server,
            'status' => IssueStatus::Open,
            'title' => 'RuntimeException',
            'message' => fake()->sentence(),
            'file' => 'app/Domains/Example/Actions/ExampleAction.php',
            'line' => fake()->numberBetween(10, 300),
            'occurrences_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'surfaced_at' => now(),
        ];
    }

    /**
     * Numbered in its project as RecordOccurrenceAction numbers it, unless a number is given.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Issue $issue): void {
            if ($issue->number !== null) {
                return;
            }

            Project::query()->whereKey($issue->project_id)->increment('last_issue_number');
            $issue->forceFill(['number' => (int) Project::query()->whereKey($issue->project_id)->value('last_issue_number')])->saveQuietly();
        });
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => IssueStatus::Resolved, 'resolved_at' => now()]);
    }

    public function ignored(): static
    {
        return $this->state(fn () => ['status' => IssueStatus::Ignored, 'ignored_at' => now()]);
    }

    public function fromBrowser(): static
    {
        return $this->state(fn () => ['source' => IssueSource::Browser, 'title' => 'TypeError']);
    }
}
