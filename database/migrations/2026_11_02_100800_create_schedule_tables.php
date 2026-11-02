<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // One row per scheduled task and environment, as the app's scheduler reports it.
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->string('environment', 64)->default('');
            $table->string('name');
            $table->string('expression', 64);
            $table->string('timezone', 64)->nullable();
            $table->string('last_status', 16)->nullable();
            $table->timestamp('last_run_at', 3)->nullable();
            $table->unsignedBigInteger('last_duration_us')->nullable();
            $table->timestamp('next_run_at', 3)->nullable();
            $table->timestamp('missed_at', 3)->nullable();
            $table->timestamp('last_seen_at', 3)->nullable();
            $table->timestamps(3);

            $table->unique(['project_id', 'environment', 'name']);
            $table->index('next_run_at');
        });

        // High volume: no foreign keys, as the other run tables.
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('scheduled_task_id');
            $table->unsignedBigInteger('occurrence_id')->nullable();
            $table->uuid('run_uuid');
            $table->string('trace_id', 64)->nullable();
            $table->string('environment', 64)->nullable();
            $table->string('host')->nullable();
            $table->string('status', 16);
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedBigInteger('duration_us')->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->timestamps(3);

            $table->unique(['project_id', 'run_uuid']);
            $table->index(['scheduled_task_id', 'started_at']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
        Schema::dropIfExists('scheduled_tasks');
    }
};
