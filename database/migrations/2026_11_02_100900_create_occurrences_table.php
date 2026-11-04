<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each time an issue happened. High volume: no foreign keys, rows are pruned in chunks by the retention jobs.
 */
return new class () extends Migration {
    public function up(): void
    {
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
            // The id of the signed-in user the error happened to, from its context.
            $table->string('user_id', 191)->nullable();

            $table->index(['issue_id', 'occurred_at']);
            $table->index(['project_id', 'occurred_at']);
            $table->index(['issue_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occurrences');
    }
};
