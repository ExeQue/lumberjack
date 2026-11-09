<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Alerts\Actions\SendProjectAlertAction;
use App\Domains\Alerts\Data\CustomAlertMessage;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\CustomAlert;
use App\Domains\Alerts\Models\NotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCustomAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Once: the action reports a channel's failure, and a retry would message the others twice. */
    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly CustomAlert $alert,
        public readonly AlertRule $rule,
    ) {
        $this->onQueue(SendIssueAlerts::QUEUE);
    }

    public function handle(SendProjectAlertAction $action): void
    {
        $project = $this->alert->project;
        $action->execute($this->rule, $project, new CustomAlertMessage($this->alert));

        $this->alert->update(['deliveries' => $this->rule->channelsFor($project)->map(fn (NotificationChannel $channel): array => [
            'channel' => $channel->name,
            'type' => $channel->type->value,
            'delivered' => NotificationChannel::query()->whereKey($channel->id)->value('last_delivery_status') === 'sent',
        ])->values()->all()]);
    }
}
