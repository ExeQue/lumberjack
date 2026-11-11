<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendWeeklySummary;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * Runs every minute: every weekly summary rule due at its day and time sends once that
 * week. A team rule covers its projects without their own; each project goes to the
 * channels its rule sends to, and every channel gets one message with them all.
 */
class SendWeeklySummariesAction
{
    /**
     * @return int summaries queued, one per channel
     */
    public function execute(): int
    {
        $now = now();
        /** @var array<int, array{channel: NotificationChannel, rules: array<int, int>}> $channels */
        $channels = [];

        $due = AlertRule::query()->whereType(AlertRuleType::WeeklySummary)->whereEnabled()->with(['project', 'channels'])->get()
            ->filter(fn (AlertRule $rule): bool => $rule->weeklySummarySettings()->isDue($rule->last_sent_at, $now));

        foreach ($due as $rule) {
            $projects = $rule->project !== null ? [$rule->project] : Project::query()
                ->where('team_id', $rule->team_id)
                ->whereDoesntHave('alertRules', fn (Builder $own) => $own->where('type', AlertRuleType::WeeklySummary->value))
                // The message's sections follow this order.
                ->orderBy('name')
                ->get();

            foreach ($projects as $project) {
                foreach ($rule->channelsFor($project) as $channel) {
                    $channels[$channel->id]['channel'] = $channel;
                    $channels[$channel->id]['rules'][$project->id] = $rule->id;
                }
            }
        }

        foreach ($channels as ['channel' => $channel, 'rules' => $rules]) {
            SendWeeklySummary::dispatch($channel, $rules);
        }

        // Marked sent once queued: a queue that cannot be reached leaves them due for the next run.
        $due->each(fn (AlertRule $rule) => $rule->forceFill(['last_sent_at' => $now])->save());

        return count($channels);
    }
}
