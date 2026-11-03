<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Gives the project a rule of its own for the type, starting as a copy of the team's,
 * so it can be changed without changing it for every project. Deleting the project's
 * rule goes back to the team's.
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
            $rule->channels()->attach($team?->channels->modelKeys() ?? []);

            return $rule;
        });
    }
}
