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
    public function execute(Project $project, AlertRuleType $type, string $topic = ''): AlertRule
    {
        $own = $project->alertRules()->where('type', $type->value)->where('topic', $topic)->first();

        if ($own !== null) {
            return $own;
        }

        $team = AlertRule::query()->whereForTeam($project->team)->whereType($type)->where('topic', $topic)->with('channels:id')->first();

        return DB::transaction(function () use ($project, $type, $topic, $team): AlertRule {
            $rule = $project->alertRules()->create([
                'type' => $type,
                'topic' => $topic,
                'enabled' => $team->enabled ?? $type->enabledByDefault(),
                'environments' => $team?->environments,
                'settings' => $team?->settings,
                // So a weekly summary the team's rule just sent is not sent again.
                'last_sent_at' => $team?->last_sent_at,
            ]);
            // The channels it sent to keep getting it: the team's and the project's add-ons,
            // which the project's own rule now holds instead.
            $rule->channels()->attach($team?->channelsFor($project)->modelKeys() ?? []);
            $team?->addOnsFor($project)->detach();

            return $rule;
        });
    }
}
