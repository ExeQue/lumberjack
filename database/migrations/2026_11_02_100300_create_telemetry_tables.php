<?php

declare(strict_types=1);

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * High-volume tables: no foreign keys, so they can be partitioned later and
 * so deleting a project never cascades millions of rows in one transaction.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('request_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('environment', 64)->nullable();
            $table->string('release', 64)->nullable();
            $table->string('host')->nullable();
            $table->string('method', 10);
            $table->string('route')->nullable();
            $table->text('url');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_us');
            $table->unsignedBigInteger('memory_peak')->nullable();
            $table->timestamp('occurred_at', 3);

            $table->index(['project_id', 'occurred_at']);
            $table->index(['project_id', 'route', 'occurred_at']);
        });

        Schema::create('query_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('environment', 64)->nullable();
            $table->string('connection', 64);
            $table->text('sql');
            $table->char('sql_hash', 64);
            $table->unsignedInteger('duration_us');
            $table->timestamp('occurred_at', 3);

            $table->index(['project_id', 'occurred_at']);
            $table->index(['project_id', 'sql_hash', 'occurred_at']);
        });

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

            $table->unique(['project_id', 'job_uuid', 'attempt']);
            $table->index(['project_id', 'name', 'queued_at']);
            $table->index(['project_id', 'status', 'started_at']);
        });

        Schema::create('log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->foreignIdFor(LogChannel::class);
            $table->string('sent_channel', 64)->nullable();
            $table->unsignedSmallInteger('level');
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('environment', 64)->nullable();
            $table->string('release', 64)->nullable();
            $table->string('host')->nullable();
            $table->timestamp('occurred_at', 3);

            $table->index(['project_id', 'log_channel_id', 'occurred_at']);
            $table->index(['project_id', 'occurred_at']);
            $table->fullText('message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_entries');
        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('query_runs');
        Schema::dropIfExists('request_runs');
    }
};
