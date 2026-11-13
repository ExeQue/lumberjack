<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events an app records (activity and analytics), and their hourly counts, which outlive
 * them. High volume: no foreign keys.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->string('group', 64);
            $table->string('name', 128);
            // Who did it and what it was done to: a type (a morph alias or class name) and a key, never more.
            $table->string('actor_type', 128)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('subject_type', 128)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('payload')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->string('environment', 64)->nullable();
            $table->string('release', 64)->nullable();
            $table->string('host')->nullable();
            $table->timestamp('occurred_at', 3);

            $table->index(['project_id', 'group', 'name', 'occurred_at']);
            $table->index(['project_id', 'group', 'name', 'actor_type', 'actor_id', 'occurred_at']);
            $table->index(['project_id', 'actor_type', 'actor_id']);
            $table->index(['project_id', 'occurred_at']);
        });

        Schema::create('activity_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            // Empty, not null, so the upsert key matches a batch without an environment.
            $table->string('environment', 64)->default('');
            $table->string('group', 64);
            $table->string('name', 128);
            $table->timestamp('hour');
            $table->unsignedBigInteger('count');

            $table->unique(['project_id', 'environment', 'group', 'name', 'hour']);
            $table->index(['project_id', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_counts');
        Schema::dropIfExists('activities');
    }
};
