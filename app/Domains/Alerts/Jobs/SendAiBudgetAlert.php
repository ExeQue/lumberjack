<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Ai\Data\AiBudgetUsage;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Alerts\Actions\SendProjectAlertAction;
use App\Domains\Alerts\Data\AiBudgetAlert;
use App\Domains\Alerts\Models\AiBudgetState;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Notifications\Actions\NotifyProjectMembersAction;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Notifications\AiAlertNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendAiBudgetAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** A budget or rule deleted since has nothing left to tell. */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  int|null  $threshold  the share passed, in percent; null for a projected overrun
     * @param  AiBudgetUsage  $usage  as the check measured it, also for a period that ended since
     * @param  int  $stateId  the period state that recorded it: one made again since tells nothing
     */
    public function __construct(
        public readonly AlertRule $rule,
        public readonly AiBudget $budget,
        public readonly ?int $threshold,
        public readonly AiBudgetUsage $usage,
        public readonly int $stateId,
    ) {
        $this->onQueue(SendIssueAlerts::QUEUE);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SendProjectAlertAction $action, NotifyProjectMembersAction $members): void
    {
        if (! $this->stillTold()) {
            return;
        }

        $project = $this->budget->project;
        $alert = new AiBudgetAlert($this->budget, $this->usage, $this->threshold, route('ai.budgets.index', $project));

        $action->execute($this->rule, $project, $alert);
        $members->execute($project, new AiAlertNotification(PushMessage::fromAlert($alert, $project, 'ai-budget-'.$this->budget->uuid)));
    }

    /**
     * Whether the state that recorded this alert still holds it: a budget counting something
     * else since, or a rule turned off and on, has a new state (or none), and an amount
     * changed since keeps only the thresholds still passed. That holds for the last look at
     * a period over too, whose state is kept closed. With the rule turned off or maintenance
     * begun since it tells nothing, and forgets it told, so the check tells it again after.
     */
    private function stillTold(): bool
    {
        if (! $this->rule->enabled || MaintenanceWindow::query()->whereProject($this->budget->project)->whereExcusing()->exists()) {
            $this->forget();

            return false;
        }

        $state = AiBudgetState::query()->whereKey($this->stateId)->first();

        return $this->threshold !== null
            ? in_array($this->threshold, $state->fired_thresholds ?? [], true)
            : $state?->projected_fired_at !== null;
    }

    /** Under the budget's lock, as the check writes its states. */
    private function forget(): void
    {
        DB::transaction(function (): void {
            AiBudget::query()->whereKey($this->budget->id)->lockForUpdate()->first();
            $state = AiBudgetState::query()->whereKey($this->stateId)->first();

            if ($state === null) {
                return;
            }

            if ($this->threshold !== null) {
                $state->fired_thresholds = array_values(array_diff($state->fired_thresholds, [$this->threshold]));
            } else {
                $state->projected_fired_at = null;
            }

            $state->save();
        });
    }
}
