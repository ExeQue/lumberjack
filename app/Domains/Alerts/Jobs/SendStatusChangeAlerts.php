<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueNote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendStatusChangeAlerts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $issueId,
        public readonly IssueStatus $status,
        public readonly string $actor,
        public readonly ?int $noteId = null,
    ) {
        $this->onQueue(SendIssueAlerts::QUEUE);
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
            $note = $this->noteId !== null ? IssueNote::query()->find($this->noteId) : null;
            $action->executeForStatusChange($issue, $this->status, $this->actor, $note);
        }
    }
}
