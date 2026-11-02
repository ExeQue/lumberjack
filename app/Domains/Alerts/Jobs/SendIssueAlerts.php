<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Issues\Models\Issue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendIssueAlerts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string QUEUE = 'alerts';

    public int $tries = 3;

    public function __construct(
        public readonly int $issueId,
        public readonly AlertRuleType $type,
        public readonly ?string $environment,
        public readonly ?int $burst = null,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SendIssueAlertsAction $action): void
    {
        $issue = Issue::query()->with('project')->find($this->issueId);

        if ($issue !== null) {
            $action->execute($issue, $this->type, $this->environment, $this->burst);
        }
    }
}
