<?php

declare(strict_types=1);

namespace App\Domains\Ai\Queries;

use App\Domains\Ai\Data\AiBudgetUsage;
use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetPayer;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Support\AiPriceBook;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * What budgets used in a period, from the same totals and prices as the AI usage pages,
 * counting the usage of the payers the budget is for (who pays for a call's key).
 */
class AiBudgetQuery
{
    public function __construct(
        private readonly AiCostQuery $costs,
    ) {
    }

    /**
     * @param  CarbonImmutable|null  $at  a moment in the period to measure, now by default: a
     *                                    past period is measured to its end
     */
    public function measure(AiBudget $budget, ?CarbonImmutable $at = null, ?AiPriceBook $book = null): AiBudgetUsage
    {
        $at ??= now();
        [$start, $end] = $budget->period->window($at);
        $total = $this->costs->total($budget->project, $budget->table(), $start, $end, null, $budget->usageFilter(), $book ?? AiPriceBook::for($budget->project));
        $cost = $budget->basis === AiBudgetBasis::Cost;

        return new AiBudgetUsage(
            $start,
            $end,
            match ($budget->payer) {
                AiBudgetPayer::All => $cost ? $total['cost'] : (float) $total['tokens'],
                AiBudgetPayer::Us => $cost ? $total['cost_ours'] : (float) $total['tokens_ours'],
                AiBudgetPayer::Customer => $cost ? $total['cost_customer'] : (float) $total['tokens_customer'],
            },
            $budget->amount,
            min(1.0, max(0.0, $start->diffInSeconds($at) / max(1, $start->diffInSeconds($end)))),
        );
    }

    /**
     * The budgets of one key (by fingerprint, without a type), key owner or actor.
     *
     * @return Collection<int, AiBudget>
     */
    public function covering(Project $project, AiBudgetScope $scope, ?string $type, string $id): Collection
    {
        return AiBudget::query()
            ->whereProject($project)
            ->whereCounting($scope, $type, $id)
            ->orderBy('name')
            ->get()
            ->each(fn (AiBudget $budget) => $budget->setRelation('project', $project));
    }
}
