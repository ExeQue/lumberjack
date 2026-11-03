<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Label;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
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

    /** Far more often than usual since this time (see DetectEscalationAction). */
    public function escalating(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => ['status' => IssueStatus::Open, 'escalated_at' => $at ?? now()->subMinutes(20), 'surfaced_at' => $at ?? now()->subMinutes(20)]);
    }

    public function inProgress(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => ['status' => IssueStatus::InProgress, 'in_progress_at' => $at ?? now()]);
    }

    /** Resolved once, and back since this time. */
    public function regressed(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => ['status' => IssueStatus::Open, 'resolved_at' => null, 'regressed_at' => $at ?? now(), 'surfaced_at' => $at ?? now()]);
    }

    /** Ignored once, and back since this time. */
    public function returned(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => ['status' => IssueStatus::Open, 'ignored_at' => null, 'returned_at' => $at ?? now(), 'regressed_at' => $at ?? now(), 'surfaced_at' => $at ?? now()]);
    }

    /**
     * Ignored until a time, an occurrence count (absolute, as ChangeIssueStatusAction
     * stores it) or an escalation; with none of them, for good.
     */
    public function ignoredUntil(?CarbonInterface $until = null, ?int $count = null, bool $escalation = false): static
    {
        return $this->ignored()->state(fn () => ['ignore_until_at' => $until, 'ignore_until_count' => $count, 'ignore_until_escalation' => $escalation]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_user_id' => $user->id]);
    }

    /** With these labels of its project. */
    public function labelled(string ...$names): static
    {
        return $this->afterCreating(fn (Issue $issue) => $issue->labels()->attach(
            Label::query()->where('project_id', $issue->project_id)->whereIn('name', $names)->pluck('id')->all(),
        ));
    }

    /** Grouped from log lines of this channel at error level or above. */
    public function fromLog(string $channel = 'default'): static
    {
        return $this->state(fn () => ['source' => IssueSource::Log, 'log_channel' => $channel, 'file' => null, 'line' => null]);
    }
}
