<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Issues\Actions\OfferMentionMailsAction;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Shared\Mail\MailRecipients;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendNoteAlerts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $noteId,
    ) {
        $this->onQueue(SendIssueAlerts::QUEUE);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * One note is one event: the mentions, the note rule's mail channels (and the issue's
     * watchers) share one MailRecipients, so an address gets one mail about it.
     */
    public function handle(SendIssueAlertsAction $action, OfferMentionMailsAction $mentions): void
    {
        $note = IssueNote::query()->with(['issue.project', 'user', 'mcpToken'])->find($this->noteId);

        if ($note === null) {
            return;
        }

        $recipients = new MailRecipients();
        $mentions->execute($note, $recipients);
        $action->executeForNote($note, $recipients);
        $recipients->send();
    }
}
