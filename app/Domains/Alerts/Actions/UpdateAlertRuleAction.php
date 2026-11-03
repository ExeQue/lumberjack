<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Data\QueueBacklogSettings;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;

class UpdateAlertRuleAction
{
    /**
     * @param  list<int>  $channelIds  the team's shared channels, and for a project's rule its own
     * @param  list<string>|null  $environments  null means every environment
     * @param  array<string, mixed>|null  $settings  a queue backlog rule's thresholds; null keeps them
     */
    public function execute(AlertRule $rule, bool $enabled, array $channelIds, ?array $environments, ?array $settings = null): AlertRule
    {
        $rule->fill(['enabled' => $enabled, 'environments' => $environments === [] ? null : $environments]);

        if ($rule->type === AlertRuleType::QueueBacklog && $settings !== null) {
            $rule->settings = QueueBacklogSettings::fromArray($settings)->toArray();
        }

        $rule->save();

        // A team rule serves every project, so it can only use the team's shared channels.
        $allowed = NotificationChannel::query()
            ->when($rule->project === null)->where('team_id', $rule->team_id)
            ->when($rule->project === null)->whereTeamWide()
            ->when($rule->project !== null)->whereAvailableTo($rule->project)
            ->whereIn('id', $channelIds)->pluck('id');
        $rule->channels()->sync($allowed);

        return $rule;
    }
}
