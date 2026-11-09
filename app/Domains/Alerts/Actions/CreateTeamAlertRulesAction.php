<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Teams\Models\Team;

/**
 * A new team's rules: one per type, on as the type is by default, with no channels yet.
 * Its projects use them until they customize one.
 */
class CreateTeamAlertRulesAction
{
    public function execute(Team $team): void
    {
        foreach (AlertRuleType::cases() as $type) {
            $team->alertRules()->firstOrCreate(['type' => $type, 'topic' => $type === AlertRuleType::CustomAlert ? AlertRule::DEFAULT_TOPIC : ''], ['enabled' => $type->enabledByDefault()]);
        }
    }
}
