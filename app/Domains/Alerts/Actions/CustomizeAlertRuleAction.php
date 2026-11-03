<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Gives the project a rule of its own for the type, starting as a copy of the team's,
 * with the channels the project added to it, so it can be changed without changing it
 * for every project. ResetAlertRuleAction goes back to the team's.
 */
class CustomizeAlertRuleAction
{
    public function execute(Project $project, AlertRuleType $type): AlertRule
    {
        $own = $project->alertRules()->where('type', $type->value)->first();

        if ($own !== null) {
            return $own;
        }

        $team = AlertRule::query()->whereForTeam($project->team)->whereType($type)->with('channels:id')->first();

        return DB::transaction(function () use ($project, $type, $team): AlertRule {
            $rule = $project->alertRules()->create([
                'type' => $type,
                'enabled' => $team->enabled ?? $type->enabledByDefault(),
                'environments' => $team?->environments,
                'settings' => $team?->settings,
            ]);
            // The channels it sent to keep getting it: the team's and the project's add-ons,
            // which the project's own rule now holds instead.
            $rule->channels()->attach($team?->channelsFor($project)->modelKeys() ?? []);
            $team?->addOnsFor($project)->detach();

            return $rule;
        });
    }
}
