<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queued job attempts. High volume: no foreign keys.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->unsignedBigInteger('occurrence_id')->nullable();
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('environment', 64)->nullable();
            $table->string('host')->nullable();
            $table->uuid('job_uuid');
            $table->string('name');
            $table->string('connection', 64);
            $table->string('queue');
            $table->unsignedSmallInteger('attempt');
            $table->unsignedSmallInteger('max_tries')->nullable();
            $table->unsignedInteger('timeout')->nullable();
            $table->string('status', 16);
            $table->timestamp('queued_at', 3)->nullable();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedBigInteger('duration_us')->nullable();
            $table->timestamps(3);
            $table->json('parameters')->nullable();
            $table->float('sample_rate')->default(1);
            $table->boolean('sampled')->default(true);

            $table->unique(['project_id', 'job_uuid', 'attempt']);
            $table->index(['project_id', 'name', 'queued_at']);
            $table->index(['project_id', 'status', 'started_at']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
