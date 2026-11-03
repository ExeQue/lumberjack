<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\AlertSend;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\Bulk;
use Database\Seeders\Support\Showcase;
use Illuminate\Database\Seeder;

/**
 * What the alerts sent about the showcase issues: a mail and a chat message when an
 * issue was new, came back or escalated, to the channels of the rule that counts for
 * the project. The Slack placeholder never takes them. A project whose issues have
 * sends is skipped.
 */
class ShowcaseAlertsSeeder extends Seeder
{
    public function run(): void
    {
        $bulk = new Bulk();

        foreach (Showcase::projects() as [$project, $spec]) {
            if (AlertSend::query()->whereIn('issue_id', Issue::query()->where('project_id', $project->id)->select('id'))->exists()) {
                continue;
            }

            foreach (Issue::query()->where('project_id', $project->id)->get() as $issue) {
                $this->send($bulk, $project, $issue, AlertRuleType::NewIssue, $issue->first_seen_at);

                if ($issue->regressed_at !== null) {
                    $this->send($bulk, $project, $issue, AlertRuleType::Regression, $issue->regressed_at);
                }

                if ($issue->escalated_at !== null) {
                    $this->send($bulk, $project, $issue, AlertRuleType::Escalation, $issue->escalated_at);
                }
            }
        }

        $bulk->flush();
    }

    private function send(Bulk $bulk, Project $project, Issue $issue, AlertRuleType $type, CarbonImmutable $at): void
    {
        $rule = AlertRule::query()->whereEffectiveFor($project)->where('type', $type->value)->first();

        if ($rule === null || ! $rule->enabled) {
            return;
        }

        foreach ($rule->channelsFor($project) as $channel) {
            /** @var NotificationChannel $channel */
            $factory = $channel->type === ChannelType::Slack && $channel->last_delivery_status === 'failed'
                ? AlertSend::factory()->failed('Slack answered 404: no_service')
                : AlertSend::factory();

            $bulk->add($factory, [
                'alert_rule_id' => $rule->id,
                'issue_id' => $issue->id,
                'notification_channel_id' => $channel->id,
                'sent_at' => $at->addSeconds(mt_rand(3, 20)),
            ]);
        }
    }
}
