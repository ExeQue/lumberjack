<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache reads, outgoing calls, mail, notifications and Redis commands on a trace. High volume: no foreign keys.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('span_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Project::class);
            $table->string('trace_id', 64)->nullable();
            $table->string('environment', 64)->nullable();
            $table->string('kind', 20);
            $table->string('name', 64);
            $table->string('target', 255);
            $table->unsignedInteger('duration_us')->nullable();
            $table->jsonb('attributes')->nullable();
            $table->timestamp('occurred_at', 3);

            $table->index(['project_id', 'trace_id']);
            $table->index(['project_id', 'kind', 'occurred_at']);
            $table->index(['project_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('span_runs');
    }
};
