<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetPayer;
use App\Domains\Ai\Enums\AiBudgetPeriod;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiBudget>
 */
class AiBudgetFactory extends Factory
{
    /**
     * The whole project, 100 of the team's currency a month.
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'scope' => AiBudgetScope::Project,
            'period' => AiBudgetPeriod::Month,
            'basis' => AiBudgetBasis::Cost,
            // Everyone's usage, so a test counts what it made unless it asks for a payer.
            'payer' => AiBudgetPayer::All,
            'amount' => 100,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    /** Counting these keys, of one project. */
    public function forKey(AiKey $key, AiKey ...$more): static
    {
        return $this->state(fn () => ['project_id' => $key->project_id, 'scope' => AiBudgetScope::Key])
            ->afterCreating(fn (AiBudget $budget) => $budget->targets()->createMany(array_map(fn (AiKey $each): array => ['fingerprint' => $each->fingerprint], [$key, ...$more])));
    }

    /**
     * Counting these key owners: a type and an id, then more as [type, id].
     *
     * @param  array{0: string, 1: string|int}  ...$more
     */
    public function forOwner(string $type, string|int $id, array ...$more): static
    {
        return $this->references(AiBudgetScope::Owner, [[$type, $id], ...array_values($more)]);
    }

    /**
     * Counting these actors: a type and an id, then more as [type, id].
     *
     * @param  array{0: string, 1: string|int}  ...$more
     */
    public function forActor(string $type, string|int $id, array ...$more): static
    {
        return $this->references(AiBudgetScope::Actor, [[$type, $id], ...array_values($more)]);
    }

    /**
     * @param  list<array{0: string, 1: string|int}>  $references
     */
    private function references(AiBudgetScope $scope, array $references): static
    {
        return $this->state(fn () => ['scope' => $scope])
            ->afterCreating(fn (AiBudget $budget) => $budget->targets()->createMany(array_map(
                fn (array $reference): array => ['ref_type' => $reference[0], 'ref_id' => (string) $reference[1]],
                $references,
            )));
    }

    /** Only the usage this payer pays for. */
    public function paidBy(AiBudgetPayer $payer): static
    {
        return $this->state(fn () => ['payer' => $payer]);
    }

    public function daily(): static
    {
        return $this->state(fn () => ['period' => AiBudgetPeriod::Day]);
    }

    /** At most this many tokens a period. */
    public function tokens(int $tokens): static
    {
        return $this->state(fn () => ['basis' => AiBudgetBasis::Tokens, 'amount' => $tokens]);
    }

    /** At most this much of the team's currency a period. */
    public function costing(float $amount): static
    {
        return $this->state(fn () => ['basis' => AiBudgetBasis::Cost, 'amount' => $amount]);
    }
}
