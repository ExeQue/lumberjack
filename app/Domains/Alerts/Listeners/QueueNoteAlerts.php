<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Listeners;

use App\Domains\Alerts\Jobs\SendNoteAlerts;
use App\Domains\Issues\Events\IssueNoteAdded;

class QueueNoteAlerts
{
    public function handle(IssueNoteAdded $event): void
    {
        SendNoteAlerts::dispatch($event->note->id);
    }
}
