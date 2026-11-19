<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetPayer;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Queries\AiBudgetQuery;
use Database\Seeders\Support\Showcase;
use Illuminate\Database\Seeder;

/**
 * A few AI budgets on the showcase projects with AI usage, sized from what they used so
 * far this period, so the Budgets page and the budget alerts have something to show: the
 * customer portal's monthly budget near its limit, its BYOK tenant over theirs, and the
 * storefront's primary key at about half of its daily tokens. A project with budgets is
 * skipped.
 */
class ShowcaseAiBudgetsSeeder extends Seeder
{
    /**
     * Per project: [factory state, share of the budget used so far].
     *
     * @var array<string, list<array{0: string, 1: float}>>
     */
    private const array BUDGETS = [
        'customer-portal' => [['project', 0.9], ['tenants', 1.2], ['users', 0.7]],
        'storefront' => [['primary', 0.5]],
    ];

    public function run(AiBudgetQuery $budgets): void
    {
        foreach (Showcase::projects() as [$project, $spec]) {
            if (! isset(self::BUDGETS[$spec['slug']]) || $project->aiBudgets()->exists() || ! $project->aiKeys()->exists()) {
                continue;
            }

            foreach (self::BUDGETS[$spec['slug']] as [$kind, $used]) {
                $factory = AiBudget::factory()->forProject($project);
                $keys = $project->aiKeys()->whereIn('label', ['prod-primary', 'fallback'])->orderBy('label')->get()->all();

                if ($kind === 'primary' && $keys === []) {
                    continue;
                }

                $factory = match ($kind) {
                    'project' => $factory->paidBy(AiBudgetPayer::Us)->state(['name' => 'Monthly AI spend']),
                    'tenants' => $factory->forOwner('tenant', 7, ['tenant', 12])->state(['name' => 'Tenants 7 and 12 (BYOK)']),
                    'users' => $factory->forActor('user', 1, ['user', 2])->daily()->state(['name' => 'Two busiest users']),
                    // One budget over both of the shop's keys.
                    'primary' => $factory->forKey(...$keys)->paidBy(AiBudgetPayer::Us)->daily()->tokens(1),
                };
                // Made with a placeholder amount, then sized from what it counts so far.
                $budget = $factory->create();
                $spent = $budgets->measure($budget->setRelation('project', $project))->spent;
                $tokens = $budget->basis === AiBudgetBasis::Tokens;
                $budget->update(['amount' => max($tokens ? 1 : 0.01, round($spent / $used, $tokens ? -3 : 2))]);
            }
        }
    }
}
