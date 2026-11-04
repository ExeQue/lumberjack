<?php

declare(strict_types=1);

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Issues\Models\Issue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each alert sent for an issue, per channel, for throttling and history.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('alert_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(AlertRule::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Issue::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(NotificationChannel::class)->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamp('sent_at');

            $table->index(['alert_rule_id', 'issue_id', 'sent_at']);
            $table->index(['issue_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_sends');
    }
};
