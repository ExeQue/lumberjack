<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health checks: reported by the app, monitored by Lumberjack, or pinged as heartbeats.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('type', 16)->nullable();
            $table->string('environment', 64)->default('');
            $table->string('slug', 100)->nullable();
            $table->string('name', 150)->nullable();
            $table->string('description', 1000)->nullable();
            $table->json('config')->nullable();
            $table->unsignedSmallInteger('interval_minutes')->nullable();
            $table->unsignedSmallInteger('degrade_after')->default(2);
            $table->unsignedSmallInteger('recover_after')->default(5);
            $table->boolean('thresholds_overridden')->default(false);
            $table->string('status', 16)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('last_result_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->string('ping_token', 64)->nullable()->unique();
            $table->timestamp('last_ping_at')->nullable();
            $table->boolean('muted')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->string('alerted_status', 16)->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();
            $table->unsignedSmallInteger('down_after')->nullable();

            // Monitored checks have no slug; Postgres lets those nulls repeat.
            $table->unique(['project_id', 'environment', 'slug']);
            $table->index(['source', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
