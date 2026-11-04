<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Errors grouped by fingerprint, with their status, numbering and counters.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Project::class)->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->string('source', 16);
            $table->string('status', 16);
            $table->string('title');
            $table->text('message');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('log_channel', 64)->nullable();
            $table->string('last_release', 64)->nullable();
            $table->string('external_issue_provider', 16)->nullable();
            $table->string('external_issue_url')->nullable();
            $table->unsignedInteger('occurrences_count')->default(0);
            $table->foreignIdFor(User::class, 'assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_seen_at', 3);
            $table->timestamp('last_seen_at', 3);
            $table->timestamp('surfaced_at', 3);
            $table->timestamp('regressed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->timestamps();
            $table->timestamp('in_progress_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            // An ignored issue comes back at this time, at this many occurrences, or when it escalates.
            $table->timestamp('ignore_until_at')->nullable();
            $table->unsignedBigInteger('ignore_until_count')->nullable();
            $table->boolean('ignore_until_escalation')->default(false);
            $table->timestamp('returned_at')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->unsignedInteger('users_count')->default(0);
            $table->string('first_release', 64)->nullable();
            $table->string('first_environment', 64)->nullable();

            $table->unique(['project_id', 'fingerprint']);
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status', 'last_seen_at']);
            $table->index(['project_id', 'surfaced_at', 'id']);
            $table->index(['status', 'ignore_until_at']);
        });

        // Trigram search on titles; skipped where the extension cannot be created.
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (QueryException $e) {
            report($e);

            return;
        }

        DB::statement('CREATE INDEX issues_title_trgm_index ON issues USING gin (title gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
