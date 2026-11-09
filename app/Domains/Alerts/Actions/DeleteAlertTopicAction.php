<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Shared\Actions\Concerns\CanHaveReasons;

/**
 * Removes a topic. Its sent alerts stay; an app sending to it again brings it back
 * without channels. Projects' own versions of it go with it.
 */
class DeleteAlertTopicAction
{
    use CanHaveReasons;

    public function execute(AlertRule $rule): bool
    {
        if ($rule->type !== AlertRuleType::CustomAlert || $rule->topic === AlertRule::DEFAULT_TOPIC) {
            $this->addReason((string) __('alerts.custom.topic_protected'));

            return false;
        }

        AlertRule::query()->whereIn('project_id', $rule->team->projects()->select('id'))->whereType($rule->type)->where('topic', $rule->topic)->delete();

        return (bool) $rule->delete();
    }
}
