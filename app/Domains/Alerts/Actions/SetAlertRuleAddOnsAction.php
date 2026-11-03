<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Projects\Models\Project;

/**
 * The channels a project adds to a team rule it uses, on top of the team's. Only
 * channels the project may use count; the rule's on/off stays the team's.
 */
class SetAlertRuleAddOnsAction
{
    /**
     * @param  list<int>  $channelIds
     */
    public function execute(Project $project, AlertRule $rule, array $channelIds): void
    {
        $allowed = NotificationChannel::query()->whereAvailableTo($project)->whereIn('id', $channelIds)->pluck('id')->all();

        $rule->addOnsFor($project)->sync($allowed);
    }
}
