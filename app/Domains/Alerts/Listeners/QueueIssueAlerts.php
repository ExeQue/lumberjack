<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Listeners;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendIssueAlerts;
use App\Domains\Issues\Events\IssueCreated;
use App\Domains\Issues\Events\IssueEscalated;
use App\Domains\Issues\Events\IssueRegressed;

class QueueIssueAlerts
{
    public function handle(IssueCreated|IssueRegressed|IssueEscalated $event): void
    {
        SendIssueAlerts::dispatch(
            $event->issue->id,
            match (true) {
                $event instanceof IssueRegressed => AlertRuleType::Regression,
                $event instanceof IssueEscalated => AlertRuleType::Escalation,
                default => AlertRuleType::NewIssue,
            },
            $event->environment,
            $event instanceof IssueEscalated ? $event->burst : null,
        );
    }
}
