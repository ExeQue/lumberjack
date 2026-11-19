<?php

declare(strict_types=1);

namespace App\Domains\Ai\Actions;

use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetPayer;
use App\Domains\Ai\Enums\AiBudgetPeriod;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiBudgetTarget;
use App\Domains\Ai\Queries\AiBudgetQuery;
use App\Domains\Alerts\Models\AiBudgetState;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Adds a budget to a project, or changes one. What its alerts told this period stays as
 * far as it is still true: a budget that now counts something else starts over, and one
 * with a new amount keeps only the thresholds it is still past and may warn again of an
 * overrun to come.
 */
class SaveAiBudgetAction
{
    public function __construct(
        private readonly AiBudgetQuery $budgets,
    ) {
    }

    /**
     * @param  array{name: string|null, scope: AiBudgetScope, targets?: list<array{fingerprint: string|null, ref_type: string|null, ref_id: string|null}>, period: AiBudgetPeriod, basis: AiBudgetBasis, payer?: AiBudgetPayer|null, amount: float}  $values
     */
    public function execute(Project $project, ?AiBudget $budget, array $values): AiBudget
    {
        return DB::transaction(function () use ($project, $budget, $values): AiBudget {
            // Under the lock the alert check takes too, so it never writes state for the old amount.
            $budget = $budget !== null
                ? AiBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail()
                : new AiBudget(['project_id' => $project->id]);
            $before = $budget->exists ? self::keysOf($budget->targets()->get()->map(fn (AiBudgetTarget $target): array => $target->only(['fingerprint', 'ref_type', 'ref_id']))->all()) : [];
            $targets = $values['scope'] === AiBudgetScope::Project ? [] : ($values['targets'] ?? []);
            $after = self::keysOf($targets);

            $budget->fill([
                'name' => $values['name'] !== null && trim($values['name']) !== '' ? trim($values['name']) : null,
                'scope' => $values['scope'],
                'period' => $values['period'],
                'basis' => $values['basis'],
                'payer' => $values['payer'] ?? ($budget->exists ? $budget->payer : AiBudgetPayer::defaultFor($values['scope'])),
                'amount' => $values['amount'],
            ]);

            $retargeted = array_values($before) !== array_values($after);
            $recounted = $budget->exists && ($retargeted || $budget->isDirty(['scope', 'period', 'basis', 'payer']));
            $resized = $budget->exists && ! $recounted && $budget->isDirty('amount');

            // The alert check measures outside the lock and writes only for the version it measured.
            if ($recounted || $resized) {
                $budget->version++;
            }

            $budget->save();

            if ($retargeted) {
                $budget->targets()->delete();
                $budget->targets()->createMany(array_values(array_map(
                    fn (array $target): array => ['fingerprint' => $target['fingerprint'], 'ref_type' => $target['ref_type'], 'ref_id' => $target['ref_id']],
                    array_intersect_key($targets, $after),
                )));
                $budget->unsetRelation('targets');
            }

            if ($recounted) {
                $budget->states()->delete();
            }

            if ($resized) {
                $budget->setRelation('project', $project);

                foreach ($budget->states()->get() as $state) {
                    $this->resize($budget, $state);
                }
            }

            return $budget;
        });
    }

    /**
     * Targets by one string each, sorted, the first of a duplicate kept, so two sets compare.
     *
     * @param  array<int, array{fingerprint?: string|null, ref_type?: string|null, ref_id?: string|null}>  $targets
     * @return array<int, string> by the target's index
     */
    private static function keysOf(array $targets): array
    {
        $keys = array_unique(array_map(fn (array $target): string => ($target['fingerprint'] ?? '')."\0".($target['ref_type'] ?? '')."\0".($target['ref_id'] ?? ''), $targets));
        asort($keys);

        return $keys;
    }

    /**
     * Keeps the thresholds the period is still past at the new amount, so the others can
     * tell again, and lets it warn again of an overrun to come.
     */
    private function resize(AiBudget $budget, AiBudgetState $state): void
    {
        $percent = $this->budgets->measure($budget, $state->period_start)->percent();

        $state->forceFill([
            'fired_thresholds' => array_values(array_filter($state->fired_thresholds, fn (int $threshold): bool => $percent >= $threshold)),
            'projected_fired_at' => null,
        ])->save();
    }
}
