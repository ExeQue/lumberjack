<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per scheduled task and environment, as the app's scheduler reports it.
 */
return new class () extends Migration {
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
};
