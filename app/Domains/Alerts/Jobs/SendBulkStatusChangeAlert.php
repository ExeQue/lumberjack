<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Alerts\Actions\SendProjectAlertAction;
use App\Domains\Alerts\Data\BulkStatusChangeAlert;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Issues\Actions\NotifyWatchersAction;
use App\Domains\Issues\Data\WatchUpdate;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Mail\MailRecipients;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBulkStatusChangeAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  list<int>  $issueIds
     */
    public function __construct(
        public readonly Project $project,
        public readonly array $issueIds,
        public readonly IssueStatus $status,
        public readonly string $actor,
        public readonly ?int $byUserId = null,
    ) {
        $this->onQueue(SendIssueAlerts::QUEUE);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SendProjectAlertAction $action, NotifyWatchersAction $watchers): void
    {
        $issues = Issue::query()->with('project')->whereKey($this->issueIds)->orderBy('number')->get();

        // The whole bulk action is one event: an address on several of its rules gets one
        // mail. The summary names more issues than anyone watches, so here it outranks the
        // watchers' mails; its footer says which of them they watch.
        $recipients = new MailRecipients();
        $by = $this->byUserId !== null ? User::query()->find($this->byUserId) : null;
        $watchers->execute(
            array_values($issues->map(fn (Issue $issue): WatchUpdate => new WatchUpdate($issue, WatchUpdate::STATUS, $this->actor, ['status' => $this->status->value]))->all()),
            $by,
            $recipients,
            MailRecipients::CHANNEL - 1,
        );

        if ($this->status !== IssueStatus::Resolved && $this->status !== IssueStatus::Ignored) {
            $recipients->send();

            return;
        }

        // The environment of each issue, as a single change takes it: its latest occurrence.
        // ponytail: one query per issue, at most 100 per bulk change.
        $environments = $issues->mapWithKeys(function (Issue $issue): array {
            $environment = $issue->occurrences()->latest('occurred_at')->value('environment');

            return [$issue->id => is_string($environment) ? $environment : null];
        });

        $rules = AlertRule::query()
            ->whereEffectiveFor($this->project)
            ->whereType(AlertRuleType::StatusChange)
            ->whereEnabled()
            ->with('channels')
            ->get();

        foreach ($rules as $rule) {
            // A bulk change spans environments, so a rule limited to some hears only about
            // the issues in them, the same ones it would have heard about one by one.
            $matching = $issues->filter(fn (Issue $issue): bool => $rule->appliesToEnvironment($environments[$issue->id]))->values();

            if ($matching->isEmpty()) {
                continue;
            }

            $action->execute($rule, $this->project, new BulkStatusChangeAlert(
                $this->project,
                $this->status,
                $this->actor,
                array_values($matching->all()),
                array_values($matching->map(fn (Issue $issue): ?string => $environments[$issue->id])->filter()->unique()->sort()->all()),
                route('issues.index', ['project' => $this->project, 'status' => $this->status->value]),
            ), $recipients);
        }

        $recipients->send();
    }
}
