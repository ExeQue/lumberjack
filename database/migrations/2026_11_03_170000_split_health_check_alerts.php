<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The one health check rule becomes three (degraded, down, recovered), each keeping its
 * on/off, environments and channels, so each can go somewhere else.
 */
return new class () extends Migration {
    private const array TYPES = ['health_degraded', 'health_down', 'health_recovered'];

    public function up(): void
    {
        foreach (DB::table('alert_rules')->where('type', 'health_check')->get() as $rule) {
            $channels = DB::table('alert_rule_notification_channel')->where('alert_rule_id', $rule->id)->pluck('notification_channel_id');

            foreach (self::TYPES as $type) {
                $id = DB::table('alert_rules')->insertGetId([
                    'project_id' => $rule->project_id,
                    'type' => $type,
                    'enabled' => $rule->enabled,
                    'environments' => $rule->environments,
                    'created_at' => $rule->created_at,
                    'updated_at' => now(),
                ]);

                DB::table('alert_rule_notification_channel')->insert($channels->map(fn (int $channel): array => ['alert_rule_id' => $id, 'notification_channel_id' => $channel])->all());
            }

            DB::table('alert_rules')->where('id', $rule->id)->delete();
        }
    }

    public function down(): void
    {
        foreach (DB::table('alert_rules')->where('type', 'health_down')->get() as $rule) {
            DB::table('alert_rules')->where('id', $rule->id)->update(['type' => 'health_check']);
        }

        DB::table('alert_rules')->whereIn('type', ['health_degraded', 'health_recovered'])->delete();
    }
};
