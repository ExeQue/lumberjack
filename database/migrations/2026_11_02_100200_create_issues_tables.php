<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

            $table->unique(['project_id', 'fingerprint']);
            $table->index(['project_id', 'status', 'last_seen_at']);
            $table->index(['project_id', 'surfaced_at', 'id']);
        });

        // High volume: no foreign keys, rows are pruned in chunks by the retention jobs.
        Schema::create('occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Issue::class);
            $table->foreignIdFor(Project::class);
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('environment', 64)->nullable();
            $table->string('release', 64)->nullable();
            $table->string('host')->nullable();
            $table->json('stacktrace')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at', 3);

            $table->index(['issue_id', 'occurred_at']);
            $table->index(['project_id', 'occurred_at']);
        });

        Schema::create('issue_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Issue::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('mcp_token_id')->nullable();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_notes');
        Schema::dropIfExists('occurrences');
        Schema::dropIfExists('issues');
    }
};
