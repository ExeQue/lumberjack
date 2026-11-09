<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Shared\Actions\Concerns\CanHaveReasons;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Str;

/**
 * A topic made ahead of the first alert to it, so its channels can be picked before the
 * code that sends to it is deployed.
 */
class CreateAlertTopicAction
{
    use CanHaveReasons;

    public function execute(Team $team, string $name): ?AlertRule
    {
        $topic = Str::limit(Str::slug($name), 64, '');

        if ($topic === '') {
            $this->addReason((string) __('alerts.custom.topic_invalid'));

            return null;
        }

        $rule = $team->alertRules()->firstOrCreate(['type' => AlertRuleType::CustomAlert, 'topic' => $topic], ['enabled' => true]);

        if (! $rule->wasRecentlyCreated) {
            $this->addReason((string) __('alerts.custom.topic_exists', ['topic' => $topic]));

            return null;
        }

        return $rule;
    }
}
