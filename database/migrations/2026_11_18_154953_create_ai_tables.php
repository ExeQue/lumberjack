<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI calls an app made (tokens, model, key and who caused them), their hourly and daily
 * totals, which outlive them, and the keys they were made with, by fingerprint only.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            // An HMAC of the hash the app sent, with the project's secret; never the key or its hash.
            $table->string('fingerprint', 64);
            $table->string('provider', 64)->nullable();
            // The driver its hash was made with, so finding a key by the key itself knows which to try.
            $table->string('driver', 64)->nullable();
            $table->string('label')->nullable();
            // Given in Lumberjack; what the app reports is the label, which never changes it.
            $table->string('name', 64)->nullable();
            // Whose key it is (a tenant bringing their own), as a type and a key.
            $table->string('owner_type', 128)->nullable();
            $table->string('owner_id', 64)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['project_id', 'fingerprint']);
            // Keys in use lately: a project's, for its pages and pruning, and across projects for
            // the AI error rate rule (Postgres before 18 cannot skip-scan the first for that).
            $table->index(['project_id', 'last_seen_at']);
            $table->index(['last_seen_at', 'project_id']);
        });

        // High volume: no foreign keys, like activities.
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->string('fingerprint', 64)->nullable();
            $table->string('provider', 64);
            $table->string('driver', 64)->nullable();
            $table->string('model', 128);
            $table->string('operation', 32);
            // Input is the total, cached tokens included; null when the provider did not say.
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cache_read_tokens')->nullable();
            $table->unsignedBigInteger('cache_write_tokens')->nullable();
            $table->unsignedBigInteger('reasoning_tokens')->nullable();
            $table->float('duration_ms')->nullable();
            $table->string('status', 16);
            $table->string('error')->nullable();
            $table->string('actor_type', 128)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('owner_type', 128)->nullable();
            $table->string('owner_id', 64)->nullable();
            $table->string('invocation_id', 64)->nullable();
            $table->unsignedInteger('step')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->string('environment', 64)->nullable();
            $table->string('release', 64)->nullable();
            $table->string('host')->nullable();
            $table->string('source', 16);
            // The queued batch it came in, so a retried batch is not counted twice.
            $table->uuid('batch')->nullable()->index();
            $table->timestamp('occurred_at', 3);

            $table->index(['project_id', 'fingerprint', 'occurred_at']);
            $table->index(['project_id', 'actor_type', 'actor_id']);
            $table->index(['project_id', 'owner_type', 'owner_id']);
        });

        // By period; covering for the AI error rate rule, which counts per key, status and environment.
        DB::statement('CREATE INDEX ai_calls_project_id_occurred_at_index ON ai_calls (project_id, occurred_at) INCLUDE (fingerprint, status, environment)');

        // Empty, not null, in every key column, so the upsert key matches a call without one.
        Schema::create('ai_usage_hourly', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->string('environment', 64)->default('');
            $table->string('provider', 64)->default('');
            $table->string('model', 128);
            $table->string('fingerprint', 64)->default('');
            // The call's owner, so a call without a key is priced and paid for as in the daily totals.
            $table->string('owner_type', 128)->default('');
            $table->string('owner_id', 64)->default('');
            $table->timestamp('hour');
            $this->counters($table);

            $table->unique(['project_id', 'environment', 'provider', 'model', 'fingerprint', 'owner_type', 'owner_id', 'hour']);
            $table->index(['project_id', 'hour']);
            // One key owner's page and budgets.
            $table->index(['project_id', 'owner_type', 'owner_id', 'hour']);
            // One key's page.
            $table->index(['project_id', 'fingerprint', 'hour']);
        });

        Schema::create('ai_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->string('environment', 64)->default('');
            $table->string('provider', 64)->default('');
            $table->string('model', 128);
            $table->string('fingerprint', 64)->default('');
            $table->string('owner_type', 128)->default('');
            $table->string('owner_id', 64)->default('');
            $table->string('actor_type', 128)->default('');
            $table->string('actor_id', 64)->default('');
            $table->date('day');
            $this->counters($table);

            $table->unique(['project_id', 'environment', 'provider', 'model', 'fingerprint', 'owner_type', 'owner_id', 'actor_type', 'actor_id', 'day']);
            // By period, and the models used lately (the prices page) from the index alone.
            $table->index(['project_id', 'day', 'provider', 'model']);
            $table->index(['project_id', 'actor_type', 'actor_id', 'day']);
            $table->index(['project_id', 'owner_type', 'owner_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
        Schema::dropIfExists('ai_usage_hourly');
        Schema::dropIfExists('ai_calls');
        Schema::dropIfExists('ai_keys');
    }

    private function counters(Blueprint $table): void
    {
        $table->unsignedBigInteger('calls')->default(0);
        $table->unsignedBigInteger('errors')->default(0);
        $table->unsignedBigInteger('input_tokens')->default(0);
        $table->unsignedBigInteger('output_tokens')->default(0);
        $table->unsignedBigInteger('cache_read_tokens')->default(0);
        $table->unsignedBigInteger('cache_write_tokens')->default(0);
        $table->unsignedBigInteger('reasoning_tokens')->default(0);
        $table->double('duration_ms_sum')->default(0);
    }
};
