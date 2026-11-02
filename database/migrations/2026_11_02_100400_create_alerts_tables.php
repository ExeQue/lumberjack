<?php

declare(strict_types=1);

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name');
            $table->text('config');
            $table->string('last_delivery_status', 16)->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->boolean('enabled')->default(true);
            $table->json('environments')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'type']);
        });

        Schema::create('alert_rule_notification_channel', function (Blueprint $table) {
            $table->foreignIdFor(AlertRule::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(NotificationChannel::class)->constrained()->cascadeOnDelete();

            $table->primary(['alert_rule_id', 'notification_channel_id']);
        });

        Schema::create('alert_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(AlertRule::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Issue::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(NotificationChannel::class)->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamp('sent_at');

            $table->index(['alert_rule_id', 'issue_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_sends');
        Schema::dropIfExists('alert_rule_notification_channel');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('notification_channels');
    }
};
