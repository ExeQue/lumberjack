<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Jobs;

use App\Domains\Alerts\Actions\SendProjectAlertAction;
use App\Domains\Alerts\Data\WeeklySummaryAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Overview\Queries\WeeklySummaryQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Mail\MailReason;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One channel's weekly summary: a section per project routed to it, those with nothing
 * in the week left out, and nothing sent when that leaves none.
 */
class SendWeeklySummary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<int, int>  $rules  project id => the id of the rule that routes it here
     */
    public function __construct(
        public readonly NotificationChannel $channel,
        public readonly array $rules,
    ) {
        $this->onQueue(SendIssueAlerts::QUEUE);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SendProjectAlertAction $action, WeeklySummaryQuery $query): void
    {
        $projects = Project::query()->whereKey(array_keys($this->rules))->with('team')->orderBy('name')->get();
        // ponytail: a project routed to several channels is summed up once per channel; cache it per run if teams grow many.
        $summaries = array_values($projects->map(fn (Project $project): ?array => $query->get($project))->filter()->all());

        if ($summaries === []) {
            return;
        }

        $rules = AlertRule::query()->whereKey(array_unique($this->rules))->get()->keyBy('id');
        // The footer names each rule that sends here once, by the first of its projects.
        $reasons = array_values($projects->unique(fn (Project $project): int => $this->rules[$project->id])
            ->map(fn (Project $project): ?MailReason => ($rule = $rules->get($this->rules[$project->id])) !== null ? MailReason::rule($rule, $this->channel, $project) : null)
            ->filter()->all());

        $action->sendTo($this->channel, new WeeklySummaryAlert($summaries, now()->subDays(7), now(), route('projects.index')), $reasons);
    }
}
