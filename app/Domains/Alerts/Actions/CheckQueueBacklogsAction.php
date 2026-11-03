<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendQueueBacklogAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\QueueBacklog;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Projects\Models\ProjectAgent;
use Illuminate\Support\Facades\DB;

/**
 * Runs every minute: compares each queue's latest reported size with the thresholds of
 * the project's queue backlog rule. Alerts once when a queue has been over them for the
 * rule's while, once when it is back under, and starts again at most once per throttle
 * window, so a queue that flaps around a threshold does not spam.
 */
class CheckQueueBacklogsAction
{
    /**
     * @return int alerts queued
     */
    public function execute(): int
    {
        $queued = 0;

        $rules = AlertRule::query()
            ->where('type', AlertRuleType::QueueBacklog->value)
            ->where('enabled', true)
            ->whereHas('channels')
            ->get();

        foreach ($rules as $rule) {
            $settings = $rule->queueBacklogSettings();
            $states = QueueBacklog::query()->where('alert_rule_id', $rule->id)->get()->keyBy(fn (QueueBacklog $b): string => $b->connection.':'.$b->queue);

            foreach ($this->latestSamples($rule->project_id) as $sample) {
                $state = $states->get($sample->connection.':'.$sample->queue)
                    ?? new QueueBacklog(['alert_rule_id' => $rule->id, 'connection' => $sample->connection, 'queue' => $sample->queue]);

                if ($settings->breached($sample->size, $sample->oldest_age_s)) {
                    $state->breaching_since ??= now();
                    $throttled = $state->last_fired_at !== null && $state->last_fired_at->greaterThan(now()->subMinutes(AlertRule::THROTTLE_MINUTES));

                    if ($state->fired_at === null && ! $throttled && $state->breaching_since->lessThanOrEqualTo(now()->subMinutes($settings->sustainMinutes))) {
                        $state->fired_at = $state->last_fired_at = now();
                        SendQueueBacklogAlert::dispatch($rule, $sample->connection, $sample->queue, $sample->size, $sample->oldest_age_s, $state->breaching_since->toIso8601String(), false);
                        $queued++;
                    }
                } else {
                    if ($state->fired_at !== null) {
                        SendQueueBacklogAlert::dispatch($rule, $sample->connection, $sample->queue, $sample->size, $sample->oldest_age_s, ($state->breaching_since ?? $state->fired_at)->toIso8601String(), true);
                        $queued++;
                    }

                    $state->breaching_since = $state->fired_at = null;
                }

                // A queue that never came near a threshold needs no row.
                if ($state->exists || $state->breaching_since !== null) {
                    $state->save();
                }
            }
        }

        return $queued;
    }

    /**
     * Each queue's latest sample from any host, if one came in while agents count as
     * online. Several hosts report the same queue; the newest number is the one that counts.
     *
     * @return iterable<QueueSample>
     */
    private function latestSamples(int $projectId): iterable
    {
        return QueueSample::query()
            ->select(DB::raw('DISTINCT ON (connection, queue) *'))
            ->where('project_id', $projectId)
            ->where('sampled_at', '>=', now()->subMinutes(ProjectAgent::OFFLINE_AFTER_MINUTES))
            ->orderBy('connection')->orderBy('queue')->orderByDesc('sampled_at')
            ->get();
    }
}
