<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;

class UpdateAlertRuleAction
{
    /**
     * @param  list<int>  $channelIds  the team's shared channels and the project's own
     * @param  list<string>|null  $environments  null means every environment
     */
    public function execute(AlertRule $rule, bool $enabled, array $channelIds, ?array $environments): AlertRule
    {
        $rule->update(['enabled' => $enabled, 'environments' => $environments === [] ? null : $environments]);

        $allowed = NotificationChannel::query()->whereAvailableTo($rule->project)->whereIn('id', $channelIds)->pluck('id');
        $rule->channels()->sync($allowed);

        return $rule;
    }
}
