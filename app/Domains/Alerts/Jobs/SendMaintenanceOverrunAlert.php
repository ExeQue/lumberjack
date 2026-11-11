<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Alerts\Actions\SendProjectAlertAction;
use App\Domains\Alerts\Data\MaintenanceOverrunAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Notifications\Actions\NotifyProjectMembersAction;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Notifications\HealthCheckNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A window overrun, or its end after one; which, the window's ended_at says.
 */
class SendMaintenanceOverrunAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly AlertRule $rule,
        public readonly MaintenanceWindow $window,
    ) {
        $this->onQueue(SendIssueAlerts::QUEUE);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SendProjectAlertAction $action, NotifyProjectMembersAction $members): void
    {
        $project = $this->window->project;
        $alert = new MaintenanceOverrunAlert($this->window, route('health.index', $project));

        $action->execute($this->rule, $project, $alert);
        // Members who want health by push get it: an app not back from maintenance is its health.
        $members->execute($project, new HealthCheckNotification(PushMessage::fromAlert($alert, $project, 'maintenance-'.$this->window->uuid)));
    }
}
