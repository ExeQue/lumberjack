<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Alerts\Actions\SendProjectAlertAction;
use App\Domains\Alerts\Data\ReleaseAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendReleaseAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly AlertRule $rule,
        public readonly Project $project,
        public readonly string $release,
        public readonly ?string $environment,
        public readonly ?string $host,
    ) {
        $this->onQueue(SendIssueAlerts::QUEUE);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SendProjectAlertAction $action): void
    {
        $action->execute($this->rule, $this->project, new ReleaseAlert(
            $this->project,
            $this->release,
            $this->environment,
            $this->host,
            route('releases.index', $this->project),
        ));
    }
}
