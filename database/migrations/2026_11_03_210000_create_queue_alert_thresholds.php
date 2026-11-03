<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's own queue backlog thresholds for one of its queues. A null field uses the
 * effective rule's; muted means the queue never alerts.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('queue_alert_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('connection');
            $table->string('queue');
            $table->unsignedInteger('size')->nullable();
            $table->unsignedInteger('oldest_minutes')->nullable();
            $table->unsignedInteger('sustained_minutes')->nullable();
            $table->boolean('muted')->default(false);
            $table->timestamps();
            $table->unique(['project_id', 'connection', 'queue']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_alert_thresholds');
    }
};
