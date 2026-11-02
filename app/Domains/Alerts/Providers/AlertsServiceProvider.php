<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Providers;

use App\Domains\Alerts\Listeners\QueueIssueAlerts;
use App\Domains\Alerts\Listeners\QueueNoteAlerts;
use App\Domains\Alerts\Listeners\QueueStatusChangeAlerts;
use App\Domains\Issues\Events\IssueCreated;
use App\Domains\Issues\Events\IssueEscalated;
use App\Domains\Issues\Events\IssueNoteAdded;
use App\Domains\Issues\Events\IssueRegressed;
use App\Domains\Issues\Events\IssueStatusChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AlertsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen([IssueCreated::class, IssueRegressed::class, IssueEscalated::class], QueueIssueAlerts::class);
        Event::listen(IssueNoteAdded::class, QueueNoteAlerts::class);
        Event::listen(IssueStatusChanged::class, QueueStatusChangeAlerts::class);
    }
}
