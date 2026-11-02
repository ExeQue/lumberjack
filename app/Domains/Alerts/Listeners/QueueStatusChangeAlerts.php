<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Listeners;

use App\Domains\Alerts\Jobs\SendStatusChangeAlerts;
use App\Domains\Issues\Events\IssueStatusChanged;

class QueueStatusChangeAlerts
{
    public function handle(IssueStatusChanged $event): void
    {
        SendStatusChangeAlerts::dispatch($event->issue->id, $event->status, $event->actor, $event->note?->id);
    }
}
