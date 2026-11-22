<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Actions\Concerns\CanHaveReasons;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Str;

/**
 * A topic made ahead of the first alert to it, so its channels can be picked before the
 * code that sends to it is deployed. A project's own topic is only its own; the team's
 * is every project's.
 */
class CreateAlertTopicAction
{
    use CanHaveReasons;

    public function execute(Team $team, string $name, ?Project $project = null): ?AlertRule
    {
        $topic = Str::limit(Str::slug($name), 64, '');

        if ($topic === '') {
            $this->addReason((string) __('alerts.custom.topic_invalid'));

            return null;
        }

        $query = AlertRule::query()->whereType(AlertRuleType::CustomAlert)->where('topic', $topic);
        // A project's topic may not hide the team's of the same name; customize that one instead.
        $taken = ($project !== null ? $query->whereEffectiveFor($project) : $query->whereForTeam($team))->exists();

        if ($taken) {
            $this->addReason((string) __('alerts.custom.topic_exists', ['topic' => $topic]));

            return null;
        }

        return ($project?->alertRules() ?? $team->alertRules())->create(['type' => AlertRuleType::CustomAlert, 'topic' => $topic, 'enabled' => true]);
    }
}
