<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\CustomAlertLevel;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\CustomAlert;
use App\Domains\Alerts\Models\NotificationChannel;
use Database\Seeders\Support\Showcase;
use Illuminate\Database\Seeder;

/**
 * A few custom alerts from the showcase projects, on topics of their teams, so the
 * Alerts page has something to show. A project with alerts is skipped.
 */
class ShowcaseCustomAlertsSeeder extends Seeder
{
    /** @var list<array{string, string, CustomAlertLevel, string, int}> */
    private const array ALERTS = [
        ['payments', 'Payment provider unreachable', CustomAlertLevel::Critical, 'Checkout is falling back to the queue.', 20],
        ['payments', 'Payment received', CustomAlertLevel::Success, 'Order #1234 was paid.', 75],
        ['deploys', 'Deploy finished', CustomAlertLevel::Info, 'Version 4f2c1a9 is live.', 190],
        ['imports', 'Import done with errors', CustomAlertLevel::Warning, '3 of 1,204 rows failed.', 420],
    ];

    public function run(): void
    {
        foreach (Showcase::projects() as [$project]) {
            if (CustomAlert::query()->where('project_id', $project->id)->exists()) {
                continue;
            }

            $channel = NotificationChannel::query()->where('team_id', $project->team_id)->whereNull('project_id')->first();

            foreach (self::ALERTS as [$topic, $title, $level, $message, $minutesAgo]) {
                $rule = AlertRule::query()->firstOrCreate(
                    ['team_id' => $project->team_id, 'type' => AlertRuleType::CustomAlert, 'topic' => $topic],
                    ['enabled' => true],
                );

                if ($channel !== null) {
                    $rule->channels()->syncWithoutDetaching([$channel->id]);
                }

                CustomAlert::factory()->create([
                    'project_id' => $project->id,
                    'alert_rule_id' => $rule->id,
                    'topic' => $topic,
                    'level' => $level,
                    'title' => $title,
                    'message' => $message,
                    'environment' => 'production',
                    'occurred_at' => now()->subMinutes($minutesAgo),
                    'deliveries' => $channel === null ? [] : [['channel' => $channel->name, 'type' => $channel->type->value, 'delivered' => true]],
                ]);
            }
        }
    }
}
