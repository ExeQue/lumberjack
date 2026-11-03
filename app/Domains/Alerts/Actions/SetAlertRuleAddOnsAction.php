<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Live\Actions\BroadcastTeamChangeAction;
use App\Domains\Live\Enums\TeamChangeKind;
use App\Domains\Projects\Models\Project;

/**
 * The channels a project adds to a team rule it uses, on top of the team's. Only
 * channels the project may use count; the rule's on/off stays the team's.
 */
class SetAlertRuleAddOnsAction
{
    public function __construct(
        private readonly BroadcastTeamChangeAction $broadcast,
    ) {
    }

    /**
     * @param  list<int>  $channelIds
     */
    public function execute(Project $project, AlertRule $rule, array $channelIds): void
    {
        $allowed = NotificationChannel::query()->whereAvailableTo($project)->whereIn('id', $channelIds)->pluck('id')->all();

        $changes = $rule->addOnsFor($project)->sync($allowed);

        // A pivot row has no model events for the live observer to hear.
        if (array_filter($changes) !== []) {
            $this->broadcast->execute($project->team_id, TeamChangeKind::Alerts);
        }
    }
}
