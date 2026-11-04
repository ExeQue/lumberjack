<?php

declare(strict_types=1);

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The channels a team alert rule sends to for one project, in place of the team's.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('project_alert_rule_channel', function (Blueprint $table) {
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(AlertRule::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(NotificationChannel::class)->constrained()->cascadeOnDelete();

            $table->primary(['project_id', 'alert_rule_id', 'notification_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_alert_rule_channel');
    }
};
