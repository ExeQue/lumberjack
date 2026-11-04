<?php

declare(strict_types=1);

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The channels an alert rule sends to.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('alert_rule_notification_channel', function (Blueprint $table) {
            $table->foreignIdFor(AlertRule::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(NotificationChannel::class)->constrained()->cascadeOnDelete();

            $table->primary(['alert_rule_id', 'notification_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rule_notification_channel');
    }
};
