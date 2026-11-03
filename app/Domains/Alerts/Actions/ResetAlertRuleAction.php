<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Models\AlertRule;
use Illuminate\Support\Facades\DB;

/**
 * Drops a project's own rule so the team's counts again. Channels the team rule does
 * not send to, such as the project's webhook, stay as add-ons on it, so a reset never
 * silently stops one.
 */
class ResetAlertRuleAction
{
    public function execute(AlertRule $rule): void
    {
        $project = $rule->project;

        if ($project === null) {
            return;
        }

        DB::transaction(function () use ($rule, $project): void {
            $team = AlertRule::query()->whereForTeam($project->team)->whereType($rule->type)->with('channels:id')->first();
            $team?->addOnsFor($project)->syncWithoutDetaching(array_values(array_diff($rule->channels()->pluck('notification_channels.id')->all(), $team->channels->modelKeys())));

            $rule->delete();
        });
    }
}
