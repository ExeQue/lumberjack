<?php

declare(strict_types=1);

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom alerts: a rule per topic, and the alerts apps sent. Every team gets the topic
 * alerts without a topic go to.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->string('topic', 64)->default('');
            $table->dropUnique(['project_id', 'type']);
            $table->dropUnique(['team_id', 'type']);
            $table->unique(['project_id', 'type', 'topic']);
            $table->unique(['team_id', 'type', 'topic']);
        });

        Schema::create('custom_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(AlertRule::class)->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 64);
            $table->string('level', 16);
            $table->string('title');
            $table->text('message')->nullable();
            $table->json('content');
            $table->string('environment', 64)->nullable();
            $table->string('host')->nullable();
            $table->string('release', 64)->nullable();
            $table->string('suppressed', 16)->nullable();
            $table->json('deliveries')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['project_id', 'occurred_at']);
        });

        $now = now();

        DB::table('alert_rules')->insertOrIgnore(DB::table('teams')->pluck('id')->map(fn (int $team): array => [
            'team_id' => $team,
            'type' => 'custom_alert',
            'topic' => 'default',
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_alerts');
        DB::table('alert_rules')->where('type', 'custom_alert')->delete();

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'type', 'topic']);
            $table->dropUnique(['team_id', 'type', 'topic']);
            $table->dropColumn('topic');
            $table->unique(['project_id', 'type']);
            $table->unique(['team_id', 'type']);
        });
    }
};
