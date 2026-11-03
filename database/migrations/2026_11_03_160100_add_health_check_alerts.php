<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every project gets the health check rule, on, with the channels of its new-issue rule.
 */
return new class () extends Migration {
    public function up(): void
    {
        $now = now();
        $missing = DB::table('projects')
            ->whereNotIn('id', DB::table('alert_rules')->where('type', 'health_check')->select('project_id'))
            ->pluck('id');

        foreach ($missing as $projectId) {
            $ruleId = DB::table('alert_rules')->insertGetId(['project_id' => $projectId, 'type' => 'health_check', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
            $channels = DB::table('alert_rule_notification_channel')
                ->whereIn('alert_rule_id', DB::table('alert_rules')->where('project_id', $projectId)->where('type', 'new_issue')->select('id'))
                ->pluck('notification_channel_id');

            DB::table('alert_rule_notification_channel')->insert($channels->map(fn (int $id): array => ['alert_rule_id' => $ruleId, 'notification_channel_id' => $id])->all());
        }
    }

    public function down(): void
    {
        DB::table('alert_rules')->where('type', 'health_check')->delete();
    }
};
