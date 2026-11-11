<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendMaintenanceOverrunAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Maintenance\Models\MaintenanceWindow;

/**
 * Runs every minute: a window still open past its expected end is alerted on through
 * the maintenance overrun rule that counts for its project, at once (no grace period)
 * and again every project's repeat interval until it ends.
 */
class CheckMaintenanceOverrunsAction
{
    /**
     * @return int alerts queued
     */
    public function execute(): int
    {
        $queued = 0;

        // Sending moves overrun_alerted_at, which the repeat check reads, so page by id.
        MaintenanceWindow::query()->whereOverrun()->with('project')->eachById(function (MaintenanceWindow $window) use (&$queued): void {
            $project = $window->project;

            if ($window->overrun_alerted_at !== null && $window->overrun_alerted_at->greaterThan(now()->subMinutes($project->maintenance_repeat_minutes))) {
                return;
            }

            $rule = AlertRule::query()->whereEffectiveFor($project)->whereType(AlertRuleType::MaintenanceOverrun)->whereEnabled()->first();

            // A rule with no channels still runs: members may want it by push (SendMaintenanceOverrunAlert).
            if ($rule === null || ! $rule->appliesToEnvironment($window->environment !== '' ? $window->environment : null)) {
                return;
            }

            $window->overrun_alerted_at = now();
            $window->save();
            SendMaintenanceOverrunAlert::dispatch($rule, $window, ended: false);
            $queued++;
        });

        return $queued;
    }
}
