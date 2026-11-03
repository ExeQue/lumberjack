<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Alerts\Actions\SendProjectAlertAction;
use App\Domains\Alerts\Data\QueueBacklogAlert;
use App\Domains\Alerts\Data\QueueBacklogSettings;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendQueueBacklogAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly AlertRule $rule,
        public readonly string $connectionName,
        public readonly string $queueName,
        public readonly int $size,
        public readonly ?int $oldestAgeSeconds,
        public readonly string $since,
        public readonly bool $recovered,
        // ponytail: nullable only for jobs queued before team rules; drop with the fallback below.
        public readonly ?Project $project = null,
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
        $project = $this->project ?? $this->rule->project;

        $action->execute($this->rule, $project, new QueueBacklogAlert(
            $project,
            $this->connectionName,
            $this->queueName,
            $this->size,
            $this->oldestAgeSeconds,
            QueueBacklogSettings::forQueue($this->rule, $project, $this->connectionName, $this->queueName),
            CarbonImmutable::parse($this->since),
            $this->recovered,
            route('jobs.index', $project),
        ));
    }
}
