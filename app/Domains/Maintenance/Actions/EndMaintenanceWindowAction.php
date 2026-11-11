<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendMaintenanceOverrunAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;

/**
 * The only way a window closes: the app's end event, or someone ending it. One that
 * ran over and was alerted on says so once more, with how long it ran over.
 */
class EndMaintenanceWindowAction
{
    public function __construct(
        private readonly BroadcastProjectChangeAction $broadcast,
    ) {
    }

    public function execute(MaintenanceWindow $window, ?CarbonImmutable $at = null): void
    {
        if ($window->ended_at !== null) {
            return;
        }

        // A planned window ended before it began never happened; it ends where it would have started.
        // Ended only if still open, then read again: an overrun alert claimed meanwhile is told it ended.
        if (MaintenanceWindow::query()->whereKey($window->id)->whereNotEnded()->update(['ended_at' => ($at ?? now())->min(now())->max($window->starts_at)]) === 0) {
            return;
        }

        $window->refresh();

        if ($window->overrun_alerted_at !== null) {
            $rule = AlertRule::query()->whereEffectiveFor($window->project)->whereType(AlertRuleType::MaintenanceOverrun)->whereEnabled()->first();

            if ($rule !== null && $rule->appliesToEnvironment($window->environment !== '' ? $window->environment : null)) {
                SendMaintenanceOverrunAlert::dispatch($rule, $window, ended: true);
            }
        }

        $this->broadcast->execute($window->project_id, ChangeKind::Health, $window->project->uuid);
    }
}
