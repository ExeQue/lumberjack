<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueEvent>
 */
class IssueEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'type' => IssueEventType::Created,
            'data' => null,
            'occurred_at' => now(),
        ];
    }

    /** The status set, by someone or (without a user) by Lumberjack. */
    public function statusChanged(IssueStatus $from, IssueStatus $to): static
    {
        return $this->state(fn () => ['type' => IssueEventType::StatusChanged, 'data' => ['from' => $from->value, 'to' => $to->value]]);
    }

    /** Back after it was resolved, in this release. */
    public function regressed(?string $release, ?string $environment = 'production'): static
    {
        return $this->state(fn () => ['type' => IssueEventType::Regressed, 'data' => ['release' => $release, 'environment' => $environment]]);
    }

    /** This many in five minutes, far more than usual. */
    public function escalated(int $burst): static
    {
        return $this->state(fn () => ['type' => IssueEventType::Escalated, 'data' => ['burst' => $burst, 'minutes' => 5]]);
    }

    public function assigned(?string $from, ?string $to): static
    {
        return $this->state(fn () => ['type' => IssueEventType::Assigned, 'data' => ['from' => $from, 'to' => $to]]);
    }

    /**
     * @param  list<string>  $added
     * @param  list<string>  $removed
     */
    public function labelsChanged(array $added, array $removed = []): static
    {
        return $this->state(fn () => ['type' => IssueEventType::LabelsChanged, 'data' => ['added' => $added, 'removed' => $removed]]);
    }

    public function repoIssueCreated(string $url): static
    {
        return $this->state(fn () => ['type' => IssueEventType::RepoIssueCreated, 'data' => ['url' => $url]]);
    }

    /** Back from being ignored, because it happened this many more times. */
    public function returned(int $occurrences): static
    {
        return $this->state(fn () => ['type' => IssueEventType::Returned, 'data' => ['reason' => 'count', 'occurrences' => $occurrences]]);
    }
}
