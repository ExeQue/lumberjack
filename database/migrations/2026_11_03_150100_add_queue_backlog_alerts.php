<?php

declare(strict_types=1);

use App\Domains\Alerts\Models\AlertRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Queue backlog alerts: rules get settings of their own (the thresholds), and each
 * queue a rule watches keeps where it stands, so it alerts once when a backlog
 * starts and once when it clears. Every project gets the rule, on, with the
 * channels of its new-issue rule.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('environments');
        });

        Schema::create('queue_backlogs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(AlertRule::class)->constrained()->cascadeOnDelete();
            $table->string('connection', 64);
            $table->string('queue');
            $table->timestamp('breaching_since')->nullable();
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();

            $table->unique(['alert_rule_id', 'connection', 'queue']);
        });

        $now = now();
        $missing = DB::table('projects')
            ->whereNotIn('id', DB::table('alert_rules')->where('type', 'queue_backlog')->select('project_id'))
            ->pluck('id');

        foreach ($missing as $projectId) {
            $ruleId = DB::table('alert_rules')->insertGetId(['project_id' => $projectId, 'type' => 'queue_backlog', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
            $channels = DB::table('alert_rule_notification_channel')
                ->whereIn('alert_rule_id', DB::table('alert_rules')->where('project_id', $projectId)->where('type', 'new_issue')->select('id'))
                ->pluck('notification_channel_id');

            DB::table('alert_rule_notification_channel')->insert($channels->map(fn (int $id): array => ['alert_rule_id' => $ruleId, 'notification_channel_id' => $id])->all());
        }
    }

    public function down(): void
    {
        DB::table('alert_rules')->where('type', 'queue_backlog')->delete();
        Schema::dropIfExists('queue_backlogs');

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
