<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Issues\Models\Issue;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Notifications\NewIssueNotification;
use App\Domains\Notifications\Notifications\ScheduledTaskNotification;
use App\Domains\Schedule\Support\ScheduledTaskIssues;
use Illuminate\Support\Facades\Cache;

/**
 * A new issue, a regression or an escalation, told by push to the members who want
 * new issues; or, when the issue is a scheduled task failing or being missed, to those
 * who want scheduled tasks instead, never both. It goes out when the team's rule of
 * that type is on and covers the environment, channels or not; an escalation at most
 * once per throttle window, as the rule's channels get it.
 */
class NotifySurfacedIssueAction
{
    public function __construct(
        private readonly NotifyProjectMembersAction $notifyProjectMembersAction,
    ) {
    }

    public function execute(Issue $issue, AlertRuleType $type, ?string $environment): void
    {
        $rules = AlertRule::query()->whereEffectiveFor($issue->project)->whereType($type)->whereEnabled()->get();

        if (! $rules->contains(fn (AlertRule $rule): bool => $rule->appliesToEnvironment($environment))) {
            return;
        }

        // A regression only follows someone resolving the issue, so each one is news.
        if ($type !== AlertRuleType::Regression && ! Cache::add('push:issue:'.$issue->id.':'.$type->value, true, now()->addMinutes(AlertRule::THROTTLE_MINUTES))) {
            return;
        }

        $scheduled = ScheduledTaskIssues::is($issue);
        $title = (string) __(match (true) {
            $scheduled => 'notifications.push.scheduled_task',
            $type === AlertRuleType::Regression => 'notifications.push.regression',
            $type === AlertRuleType::Escalation => 'notifications.push.escalation',
            default => 'notifications.push.new_issue',
        }, ['title' => $issue->title]);

        $message = PushMessage::about(
            $issue->project,
            $title,
            $issue->project->name.' - '.mb_substr($issue->message, 0, 200),
            route('issues.show', [$issue->project, $issue]),
            'issue-'.$issue->uuid,
        );

        $this->notifyProjectMembersAction->execute($issue->project, $scheduled ? new ScheduledTaskNotification($message) : new NewIssueNotification($message));
    }
}
