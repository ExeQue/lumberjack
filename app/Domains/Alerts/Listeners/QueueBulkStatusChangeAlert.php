<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Listeners;

use App\Domains\Alerts\Jobs\SendBulkStatusChangeAlert;
use App\Domains\Issues\Events\IssuesStatusChangedInBulk;

class QueueBulkStatusChangeAlert
{
    public function handle(IssuesStatusChangedInBulk $event): void
    {
        SendBulkStatusChangeAlert::dispatch($event->project, $event->issueIds, $event->status, $event->actor, $event->byUserId);
    }
}
