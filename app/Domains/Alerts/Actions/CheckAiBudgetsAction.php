<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Ai\Data\AiBudgetUsage;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Queries\AiBudgetQuery;
use App\Domains\Ai\Support\AiPriceBook;
use App\Domains\Alerts\Data\AiBudgetSettings;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendAiBudgetAlert;
use App\Domains\Alerts\Models\AiBudgetState;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs every five minutes: measures each budget's current period against the AI budget
 * rule that counts for its project. Tells once per period about each threshold it
 * passed (only the highest, when several were passed at once), and once when it is on
 * course to go over. The period just ended is measured once more to its end, after a
 * grace for calls still on their way, so what was spent in its last minutes is told too.
 * During maintenance it tells nothing; what was passed meanwhile in the current period is
 * told after. Without an enabled rule it forgets what it told, so a rule turned on again
 * starts over.
 */
class CheckAiBudgetsAction
{
    /** How long after a period ends its late calls may still come in before it is measured. */
    public const int GRACE_MINUTES = 5;

    public function __construct(
        private readonly AiBudgetQuery $budgets,
    ) {
    }

    /**
     * @return int alerts queued
     */
    public function execute(): int
    {
        $queued = 0;

        // ponytail: one cost query per budget every five minutes; batch them per project if projects keep many budgets.
        /** @var Collection<int, Collection<int, AiBudget>> $byProject */
        $byProject = AiBudget::query()->with(['project.team', 'targets'])->orderBy('id')->get()->groupBy('project_id');

        foreach ($byProject as $budgets) {
            $project = $budgets->first()->project;
            $rule = AlertRule::query()->whereEffectiveFor($project)->whereType(AlertRuleType::AiBudget)->whereEnabled()->first();

            if ($rule === null) {
                AiBudgetState::query()->whereIn('ai_budget_id', $budgets->pluck('id'))->delete();

                continue;
            }

            if (MaintenanceWindow::query()->whereProject($project)->whereExcusing()->exists()) {
                continue;
            }

            $settings = $rule->aiBudgetSettings();
            $book = AiPriceBook::for($project);

            foreach ($budgets as $budget) {
                $queued += $this->check($rule, $settings, $budget, $book);
            }
        }

        return $queued;
    }

    /**
     * @return int alerts queued
     */
    private function check(AlertRule $rule, AiBudgetSettings $settings, AiBudget $budget, AiPriceBook $book): int
    {
        [$start] = $budget->period->window(now());
        $queued = 0;

        foreach ($budget->states()->where('period_start', '<', $start)->get() as $ended) {
            [, $end] = $budget->period->window($ended->period_start);

            // Only the period just before this one is worth a last look; older ones were missed.
            // A closed one had it and stays, for its alerts' jobs, until the next period ends.
            if (! $end->equalTo($start)) {
                $ended->delete();

                continue;
            }

            if ($ended->closed_at === null && now()->greaterThanOrEqualTo($end->addMinutes(self::GRACE_MINUTES))) {
                $queued += $this->record($rule, $settings, $budget, $this->budgets->measure($budget, $end->subSecond(), $book), ended: true);
            }
        }

        return $queued + $this->record($rule, $settings, $budget, $this->budgets->measure($budget, now(), $book), ended: false);
    }

    /**
     * Tells what a measurement passed and keeps that in the period's state, under the
     * budget's lock, unless what it counts or its amount changed since it was measured (its
     * version moved on): the next run then measures it as it is now.
     *
     * @param  bool  $ended  a period over: its state is closed once told, kept for the jobs of its alerts
     * @return int alerts queued
     */
    private function record(AlertRule $rule, AiBudgetSettings $settings, AiBudget $budget, AiBudgetUsage $usage, bool $ended): int
    {
        return DB::transaction(function () use ($rule, $settings, $budget, $usage, $ended): int {
            $locked = AiBudget::query()->whereKey($budget->id)->lockForUpdate()->first();

            if ($locked === null || $locked->version !== $budget->version) {
                return 0;
            }

            $state = $ended
                ? $budget->states()->where('period_start', $usage->start)->whereNull('closed_at')->first()
                : AiBudgetState::query()->firstOrNew(['ai_budget_id' => $budget->id, 'period_start' => $usage->start]);

            if ($state === null) {
                return 0;
            }

            // Saved before an alert names it, so its job finds this state and no later one.
            if (! $state->exists) {
                $state->save();
            }

            $queued = $this->tell($rule, $settings, $budget, $state, $usage, ! $ended && $settings->projected);

            if ($ended) {
                $state->closed_at = now();
                $state->save();
            } elseif ($state->isDirty()) {
                // Kept from the period's first check on, so its end is measured even when nothing was told yet.
                $state->save();
            }

            return $queued;
        });
    }

    /**
     * @return int alerts queued
     */
    private function tell(AlertRule $rule, AiBudgetSettings $settings, AiBudget $budget, AiBudgetState $state, AiBudgetUsage $usage, bool $projected): int
    {
        // Above the highest told: one lowered with the amount does not tell again.
        $told = max([0, ...$state->fired_thresholds]);
        $passed = array_values(array_filter(
            $settings->thresholds,
            fn (int $threshold): bool => $threshold > $told && $usage->percent() >= $threshold,
        ));

        if ($passed !== []) {
            $state->fired_thresholds = [...$state->fired_thresholds, ...$passed];
            SendAiBudgetAlert::dispatch($rule, $budget, max($passed), $usage, $state->id)->afterCommit();

            return 1;
        }

        if ($projected && $state->projected_fired_at === null && $usage->projectsOverrun()) {
            $state->projected_fired_at = now();
            SendAiBudgetAlert::dispatch($rule, $budget, null, $usage, $state->id)->afterCommit();

            return 1;
        }

        return 0;
    }
}
