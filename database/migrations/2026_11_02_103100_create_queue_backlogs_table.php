<?php

declare(strict_types=1);

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queues breaching a backlog alert rule, per project, and when they last fired.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('queue_backlogs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(AlertRule::class)->constrained()->cascadeOnDelete();
            $table->string('connection', 64);
            $table->string('queue');
            $table->timestamp('breaching_since')->nullable();
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();

            $table->unique(['alert_rule_id', 'project_id', 'connection', 'queue']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_backlogs');
    }
};
