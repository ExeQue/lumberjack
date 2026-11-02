<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('in_progress_at');
        });

        // Every project gets an escalation rule, on, to the channels its new-issue rule uses.
        $now = now();

        foreach (DB::table('alert_rules')->where('type', 'new_issue')->get() as $rule) {
            if (DB::table('alert_rules')->where('project_id', $rule->project_id)->where('type', 'escalation')->exists()) {
                continue;
            }

            $id = DB::table('alert_rules')->insertGetId([
                'project_id' => $rule->project_id, 'type' => 'escalation', 'enabled' => true,
                'environments' => $rule->environments, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $channels = DB::table('alert_rule_notification_channel')->where('alert_rule_id', $rule->id)->pluck('notification_channel_id');

            DB::table('alert_rule_notification_channel')->insert($channels->map(fn ($channel): array => [
                'alert_rule_id' => $id, 'notification_channel_id' => $channel,
            ])->all());
        }
    }

    public function down(): void
    {
        DB::table('alert_rules')->where('type', 'escalation')->delete();

        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }
};
