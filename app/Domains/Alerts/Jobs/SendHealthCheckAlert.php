<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Alerts\Actions\SendProjectAlertAction;
use App\Domains\Alerts\Data\HealthCheckAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Notifications\Actions\NotifyProjectMembersAction;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Notifications\HealthCheckNotification;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendHealthCheckAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  list<string>  $hosts
     */
    public function __construct(
        public readonly AlertRule $rule,
        public readonly HealthCheck $check,
        public readonly string $state,
        public readonly ?string $message,
        public readonly array $hosts,
        public readonly string $since,
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
        $alert = new HealthCheckAlert(
            $this->check,
            $this->state,
            $this->message,
            $this->hosts,
            CarbonImmutable::parse($this->since),
            route('health.index', ['project' => $this->check->project, 'check' => $this->check->uuid]),
        );

        $action->execute($this->rule, $this->check->project, $alert);
        $members->execute($this->check->project, new HealthCheckNotification(PushMessage::fromAlert($alert, $this->check->project, 'health-'.$this->check->uuid)));
    }
}
